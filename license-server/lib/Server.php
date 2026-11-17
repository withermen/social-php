<?php

namespace LicenseServer;

final class Server
{
    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly array $config,
        private readonly Store $store,
        private readonly ?Signer $signer
    ) {
    }

    public function plans(): array
    {
        $plans = [];

        foreach ((array) ($this->config['plans'] ?? []) as $code => $plan) {
            $plans[$code] = [
                'code' => $code,
                'name' => $plan['name'] ?? $code,
                'price_cents' => (int) ($plan['price_cents'] ?? 0),
                'currency' => $plan['currency'] ?? 'TRY',
                'duration_days' => (int) ($plan['duration_days'] ?? 30),
                'max_activations' => (int) ($plan['max_activations'] ?? 1),
                'features' => array_values((array) ($plan['features'] ?? [])),
            ];
        }

        return $plans;
    }

    public function handle(string $method, string $path, array $body, array $headers, string $ip): array
    {
        $nonce = (string) ($body['nonce'] ?? '');
        $ipHash = hash('sha256', $ip . '|' . ($this->config['admin_token'] ?? ''));

        if (!$this->store->allow($ipHash, (int) ($this->config['rate_limit'] ?? 120))) {
            return $this->error('rate_limited', 'Too many requests.', $nonce, 429);
        }

        try {
            return match ($path) {
                '/health' => $this->health($nonce),
                '/plans' => $this->signed(['ok' => true, 'data' => ['plans' => $this->plans()]], $nonce),
                '/activate' => $this->activate($method, $body, $ipHash, $nonce),
                '/verify' => $this->verify($method, $body, $ipHash, $nonce),
                '/deactivate' => $this->deactivate($method, $body, $ipHash, $nonce),
                '/admin/issue' => $this->adminIssue($method, $body, $headers, $ipHash, $nonce),
                '/admin/licenses' => $this->adminList($method, $headers, $nonce),
                '/admin/revoke' => $this->adminRevoke($method, $body, $headers, $ipHash, $nonce),
                '/admin/stats' => $this->adminStats($method, $headers, $nonce),
                default => $this->error('not_found', 'Endpoint not found.', $nonce, 404),
            };
        } catch (\Throwable $exception) {
            return $this->error('server_error', $this->config['debug'] ?? false ? $exception->getMessage() : 'License server error.', $nonce, 500);
        }
    }

    private function health(string $nonce): array
    {
        return $this->signed([
            'ok' => true,
            'data' => array_merge([
                'service' => 'socialphp-license',
                'version' => '1.0.0',
                'time' => gmdate('c'),
            ], $this->store->stats()),
        ], $nonce);
    }

    private function activate(string $method, array $body, string $ipHash, string $nonce): array
    {
        if ($method !== 'POST') {
            return $this->error('method_not_allowed', 'Use POST.', $nonce, 405);
        }

        $nonceError = $this->checkNonce($nonce);

        if ($nonceError !== null) {
            return $nonceError;
        }

        $key = trim((string) ($body['key'] ?? ''));
        $domain = $this->normalizeDomain((string) ($body['domain'] ?? ''));
        $installation = $this->normalizeInstallation((string) ($body['installation_id'] ?? ''));

        if ($key === '' || $domain === '' || $installation === '') {
            return $this->error('invalid_request', 'key, domain and installation_id are required.', $nonce, 422);
        }

        $license = $this->store->licenseByKey($key);

        if ($license === null) {
            return $this->error('license_unknown', 'License key not found.', $nonce, 404);
        }

        $failure = $this->licenseFailure($license, $domain, $installation, true);

        if ($failure !== null) {
            $this->store->audit('activate_failed', $license['id'], $domain, $installation, $ipHash, ['error' => $failure['error']]);

            return $this->signed(['ok' => false, 'error' => $failure['error'], 'message' => $failure['message']], $nonce, 403);
        }

        $now = time();
        $existing = $this->store->activation($license['id'], $installation);

        if ($existing === null) {
            if ($this->store->countActivations($license['id']) >= (int) $license['max_activations']) {
                return $this->signed(['ok' => false, 'error' => 'activation_limit', 'message' => 'Activation limit reached.'], $nonce, 403);
            }

            $this->store->insertActivation([
                'id' => bin2hex(random_bytes(12)),
                'license_id' => $license['id'],
                'installation_id' => $installation,
                'domain' => $domain,
                'ip_hash' => $ipHash,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            $this->store->touchActivation($existing['id'], $domain, $ipHash);
        }

        $this->store->updateLicense($license['id'], [
            'domain' => $license['domain'] ?? $domain,
            'installation_id' => $license['installation_id'] ?? $installation,
            'activation_count' => $this->store->countActivations($license['id']),
            'last_seen_at' => $now,
        ]);
        $this->store->audit('activate', $license['id'], $domain, $installation, $ipHash);

        return $this->signed([
            'ok' => true,
            'data' => $this->claims($this->store->licenseById($license['id'])),
        ], $nonce);
    }

    private function verify(string $method, array $body, string $ipHash, string $nonce): array
    {
        if ($method !== 'POST') {
            return $this->error('method_not_allowed', 'Use POST.', $nonce, 405);
        }

        $nonceError = $this->checkNonce($nonce);

        if ($nonceError !== null) {
            return $nonceError;
        }

        $key = trim((string) ($body['key'] ?? ''));
        $domain = $this->normalizeDomain((string) ($body['domain'] ?? ''));
        $installation = $this->normalizeInstallation((string) ($body['installation_id'] ?? ''));
        $license = $key === '' ? null : $this->store->licenseByKey($key);

        if ($license === null) {
            return $this->signed(['ok' => false, 'error' => 'license_unknown', 'message' => 'License key not found.'], $nonce, 403);
        }

        $failure = $this->licenseFailure($license, $domain, $installation, false);

        if ($failure !== null) {
            $this->store->audit('verify_failed', $license['id'], $domain, $installation, $ipHash, ['error' => $failure['error']]);

            return $this->signed([
                'ok' => false,
                'error' => $failure['error'],
                'message' => $failure['message'],
                'license_id' => $license['id'],
                'plan' => $license['plan'],
                'expires_at' => $license['expires_at'] === null ? null : gmdate('c', (int) $license['expires_at']),
            ], $nonce, 403);
        }

        $now = time();
        $this->store->updateLicense($license['id'], ['last_seen_at' => $now]);
        $activation = $this->store->activation($license['id'], $installation);

        if ($activation !== null) {
            $this->store->touchActivation($activation['id'], $domain, $ipHash);
        }

        $this->store->audit('verify', $license['id'], $domain, $installation, $ipHash);

        return $this->signed([
            'ok' => true,
            'data' => $this->claims($this->store->licenseById($license['id'])),
        ], $nonce);
    }

    private function deactivate(string $method, array $body, string $ipHash, string $nonce): array
    {
        if ($method !== 'POST') {
            return $this->error('method_not_allowed', 'Use POST.', $nonce, 405);
        }

        $nonceError = $this->checkNonce($nonce);

        if ($nonceError !== null) {
            return $nonceError;
        }

        $key = trim((string) ($body['key'] ?? ''));
        $installation = $this->normalizeInstallation((string) ($body['installation_id'] ?? ''));
        $license = $key === '' ? null : $this->store->licenseByKey($key);

        if ($license === null) {
            return $this->signed(['ok' => false, 'error' => 'license_unknown', 'message' => 'License key not found.'], $nonce, 403);
        }

        $removed = $this->store->deleteActivation($license['id'], $installation);
        $count = $this->store->countActivations($license['id']);
        $this->store->updateLicense($license['id'], [
            'activation_count' => $count,
            'installation_id' => $count === 0 ? null : $license['installation_id'],
        ]);
        $this->store->audit('deactivate', $license['id'], null, $installation, $ipHash, ['removed' => $removed]);

        return $this->signed(['ok' => true, 'data' => ['deactivated' => $removed, 'activations' => $count]], $nonce);
    }

    private function adminIssue(string $method, array $body, array $headers, string $ipHash, string $nonce): array
    {
        if ($method !== 'POST') {
            return $this->error('method_not_allowed', 'Use POST.', $nonce, 405);
        }

        if (!$this->isAdmin($headers)) {
            return $this->error('forbidden', 'Admin token is invalid.', $nonce, 403);
        }

        $planCode = (string) ($body['plan'] ?? 'monthly');
        $plans = $this->plans();

        if (!isset($plans[$planCode])) {
            return $this->error('unknown_plan', 'Unknown license plan.', $nonce, 422);
        }

        $plan = $plans[$planCode];
        $email = trim((string) ($body['email'] ?? ''));
        $domain = $this->normalizeDomain((string) ($body['domain'] ?? ''));
        $days = isset($body['duration_days']) ? max(1, (int) $body['duration_days']) : (int) $plan['duration_days'];
        $activations = isset($body['max_activations']) ? max(1, (int) $body['max_activations']) : (int) $plan['max_activations'];
        $key = $this->generateKey();
        $now = time();
        $id = bin2hex(random_bytes(12));

        $this->store->insertLicense([
            'id' => $id,
            'key_hash' => hash('sha256', $key),
            'key_preview' => substr($key, 0, 16),
            'plan' => $planCode,
            'holder_name' => $body['holder_name'] ?? null,
            'email' => $email === '' ? null : $email,
            'status' => 'active',
            'domain' => $domain === '' ? null : $domain,
            'installation_id' => null,
            'max_activations' => $activations,
            'activation_count' => 0,
            'issued_at' => $now,
            'expires_at' => $now + $days * 86400,
            'revoked_at' => null,
            'last_seen_at' => null,
            'meta' => json_encode(['features' => $plan['features']], JSON_UNESCAPED_SLASHES),
        ]);
        $this->store->audit('issue', $id, $domain, null, $ipHash, ['plan' => $planCode, 'days' => $days]);

        return $this->signed([
            'ok' => true,
            'data' => [
                'key' => $key,
                'license' => $this->claims($this->store->licenseById($id)),
            ],
        ], $nonce);
    }

    private function adminList(string $method, array $headers, string $nonce): array
    {
        if ($method !== 'GET') {
            return $this->error('method_not_allowed', 'Use GET.', $nonce, 405);
        }

        if (!$this->isAdmin($headers)) {
            return $this->error('forbidden', 'Admin token is invalid.', $nonce, 403);
        }

        $licenses = array_map(fn (array $row): array => $this->claims($row), $this->store->allLicenses());

        return $this->signed(['ok' => true, 'data' => ['licenses' => $licenses]], $nonce);
    }

    private function adminRevoke(string $method, array $body, array $headers, string $ipHash, string $nonce): array
    {
        if ($method !== 'POST') {
            return $this->error('method_not_allowed', 'Use POST.', $nonce, 405);
        }

        if (!$this->isAdmin($headers)) {
            return $this->error('forbidden', 'Admin token is invalid.', $nonce, 403);
        }

        $id = trim((string) ($body['license_id'] ?? ''));
        $license = $this->store->licenseById($id);

        if ($license === null) {
            return $this->error('license_unknown', 'License not found.', $nonce, 404);
        }

        $this->store->updateLicense($id, [
            'status' => 'revoked',
            'revoked_at' => time(),
        ]);
        $this->store->audit('revoke', $id, $license['domain'], $license['installation_id'], $ipHash);

        return $this->signed(['ok' => true, 'data' => $this->claims($this->store->licenseById($id))], $nonce);
    }

    private function adminStats(string $method, array $headers, string $nonce): array
    {
        if ($method !== 'GET') {
            return $this->error('method_not_allowed', 'Use GET.', $nonce, 405);
        }

        if (!$this->isAdmin($headers)) {
            return $this->error('forbidden', 'Admin token is invalid.', $nonce, 403);
        }

        return $this->signed(['ok' => true, 'data' => $this->store->stats()], $nonce);
    }

    private function licenseFailure(array $license, string $domain, string $installation, bool $bind): ?array
    {
        if (($license['status'] ?? 'active') !== 'active') {
            return ['error' => 'license_revoked', 'message' => 'License has been revoked.'];
        }

        if ($license['expires_at'] !== null && (int) $license['expires_at'] <= time()) {
            return ['error' => 'license_expired', 'message' => 'License has expired.'];
        }

        if ($license['domain'] !== null && $license['domain'] !== '' && $license['domain'] !== $domain) {
            return ['error' => 'domain_mismatch', 'message' => 'License is bound to another domain.'];
        }

        if ($license['installation_id'] !== null && $license['installation_id'] !== '' && $license['installation_id'] !== $installation) {
            return ['error' => 'installation_mismatch', 'message' => 'License is bound to another installation.'];
        }

        if (!$bind && $license['installation_id'] === null) {
            return ['error' => 'activation_required', 'message' => 'License must be activated first.'];
        }

        if ($license['installation_id'] === null && $license['domain'] === null && $domain === '') {
            return ['error' => 'invalid_request', 'message' => 'domain is required.'];
        }

        return null;
    }

    private function claims(array $license): array
    {
        $meta = json_decode((string) ($license['meta'] ?? '{}'), true);
        $features = is_array($meta) ? (array) ($meta['features'] ?? []) : [];
        $plan = $this->plans()[$license['plan']] ?? null;

        return [
            'valid' => ($license['status'] ?? '') === 'active'
                && ($license['expires_at'] === null || (int) $license['expires_at'] > time()),
            'license_id' => $license['id'],
            'plan' => $license['plan'],
            'plan_name' => $plan['name'] ?? $license['plan'],
            'features' => $features,
            'holder_name' => $license['holder_name'] ?? null,
            'email' => $license['email'] ?? null,
            'domain' => $license['domain'] ?? null,
            'installation_id' => $license['installation_id'] ?? null,
            'status' => $license['status'],
            'max_activations' => (int) $license['max_activations'],
            'activation_count' => (int) $license['activation_count'],
            'issued_at' => gmdate('c', (int) $license['issued_at']),
            'expires_at' => $license['expires_at'] === null ? null : gmdate('c', (int) $license['expires_at']),
            'last_seen_at' => $license['last_seen_at'] === null ? null : gmdate('c', (int) $license['last_seen_at']),
            'checked_at' => gmdate('c'),
            'next_check_seconds' => (int) ($this->config['check_interval'] ?? 3600),
            'grace_hours' => (int) ($this->config['grace_hours'] ?? 72),
        ];
    }

    private function checkNonce(string $nonce): ?array
    {
        if (strlen($nonce) < 16 || strlen($nonce) > 128) {
            return $this->error('invalid_nonce', 'nonce must be between 16 and 128 characters.', $nonce, 422);
        }

        if (!$this->store->acceptNonce($nonce)) {
            return $this->error('nonce_reused', 'Nonce has already been used.', $nonce, 409);
        }

        return null;
    }

    private function isAdmin(array $headers): bool
    {
        $token = (string) ($this->config['admin_token'] ?? '');

        if ($token === '') {
            return false;
        }

        $provided = (string) ($headers['x-admin-token'] ?? '');

        if ($provided === '') {
            $authorization = (string) ($headers['authorization'] ?? '');

            if (stripos($authorization, 'Bearer ') === 0) {
                $provided = trim(substr($authorization, 7));
            }
        }

        return $provided !== '' && hash_equals($token, $provided);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));

        if ($domain === '') {
            return '';
        }

        if (str_contains($domain, '://')) {
            $domain = (string) (parse_url($domain, PHP_URL_HOST) ?? $domain);
        }

        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = ltrim($domain, '.');
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        return rtrim($domain, '.');
    }

    private function normalizeInstallation(string $installation): string
    {
        $installation = trim($installation);

        return mb_substr($installation, 0, 120, 'UTF-8');
    }

    private function generateKey(): string
    {
        $groups = [];

        for ($i = 0; $i < 4; $i++) {
            $group = '';

            for ($j = 0; $j < 4; $j++) {
                $group .= self::KEY_ALPHABET[random_int(0, strlen(self::KEY_ALPHABET) - 1)];
            }

            $groups[] = $group;
        }

        return 'SOC-' . implode('-', $groups);
    }

    private function signed(array $data, string $nonce, int $status = 200): array
    {
        $ts = time();
        $payload = [
            'data' => $data['data'] ?? null,
            'ok' => (bool) ($data['ok'] ?? false),
            'error' => $data['error'] ?? null,
            'message' => $data['message'] ?? null,
            'ts' => $ts,
            'nonce' => $nonce,
        ];

        if ($this->signer !== null) {
            $payload['sig'] = $this->signer->sign([
                'data' => $payload['data'],
                'ok' => $payload['ok'],
                'error' => $payload['error'],
                'message' => $payload['message'],
                'ts' => $payload['ts'],
                'nonce' => $payload['nonce'],
            ]);
        }

        return [$status, $payload];
    }

    private function error(string $error, string $message, string $nonce, int $status): array
    {
        return $this->signed(['ok' => false, 'error' => $error, 'message' => $message], $nonce, $status);
    }
}
