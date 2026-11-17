<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;
use Social\Support\Validator;

final class LicenseService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly App $app)
    {
    }

    public function plans(): array
    {
        $plans = [];

        foreach ((array) $this->app->config()->get('licensing.plans', []) as $code => $plan) {
            $plans[] = [
                'code' => $code,
                'name' => $plan['name'] ?? ucfirst($code),
                'description' => $plan['description'] ?? null,
                'price_cents' => (int) ($plan['price_cents'] ?? 0),
                'price' => number_format(((int) ($plan['price_cents'] ?? 0)) / 100, 2, '.', ''),
                'currency' => $plan['currency'] ?? 'USD',
                'duration_days' => (int) ($plan['duration_days'] ?? 365),
                'max_activations' => (int) ($plan['max_activations'] ?? 1),
                'features' => array_values((array) ($plan['features'] ?? [])),
            ];
        }

        return $plans;
    }

    public function plan(string $code): array
    {
        $plan = $this->app->config()->get('licensing.plans.' . $code);

        if (!is_array($plan)) {
            throw ApiException::notFound('License plan not found.');
        }

        return $plan;
    }

    public function status(): array
    {
        $enforcement = $this->enforcement();
        $enabled = (bool) $this->app->config()->get('licensing.enabled', false);
        $remote = $this->app->remoteLicense();
        $plan = $remote->plan();
        $mode = $remote->mode();

        if ($plan === 'free') {
            return [
                'required' => false,
                'enabled' => $enabled,
                'enforcement' => $enforcement,
                'mode' => $mode,
                'plan' => $plan,
                'provided' => false,
                'valid' => true,
                'licensed' => false,
                'holder' => null,
                'expires_at' => null,
                'error' => null,
                'features' => $remote->features(),
                'remote' => null,
                'plans' => $this->plans(),
            ];
        }

        if ($remote->remoteRequired()) {
            $remoteStatus = $remote->status();

            return [
                'required' => true,
                'enabled' => $enabled,
                'enforcement' => 'enforce',
                'mode' => $mode,
                'plan' => $plan,
                'provided' => trim((string) $this->app->config()->get('licensing.key', '')) !== '',
                'valid' => (bool) ($remoteStatus['valid'] ?? false),
                'licensed' => (bool) ($remoteStatus['valid'] ?? false),
                'holder' => $remoteStatus['payload']['holder_name'] ?? null,
                'expires_at' => $remoteStatus['expires_at'] ?? null,
                'error' => $remoteStatus['error'] ?? (($remoteStatus['valid'] ?? false) ? null : 'license_not_verified'),
                'features' => $remote->features(),
                'remote' => $remoteStatus,
                'plans' => $this->plans(),
            ];
        }

        $key = trim((string) $this->app->config()->get('licensing.key', ''));
        $verified = $key !== '' ? $this->verify($key) : null;
        $required = $enabled && $enforcement !== 'off';
        $valid = !$required || ($verified['valid'] ?? false);

        return [
            'required' => $required,
            'enabled' => $enabled,
            'enforcement' => $enforcement,
            'mode' => $mode,
            'provided' => $key !== '',
            'valid' => $valid,
            'licensed' => (bool) ($verified['valid'] ?? false),
            'plan' => $verified['plan'] ?? $plan,
            'holder' => $verified['holder_name'] ?? null,
            'expires_at' => $verified['expires_at'] ?? null,
            'error' => $verified['error'] ?? ($required && $key === '' ? 'license_key_missing' : null),
            'features' => $remote->features(),
            'remote' => null,
            'plans' => $this->plans(),
        ];
    }

    public function guard(): array
    {
        $remote = $this->app->remoteLicense();

        if ($remote->isFree()) {
            return $this->status();
        }

        if ($remote->remoteRequired()) {
            $status = $remote->guard();

            return array_merge($this->status(), [
                'valid' => (bool) ($status['valid'] ?? false),
                'remote' => $status,
            ]);
        }

        $status = $this->status();

        if ($status['required'] && !$status['valid']) {
            $message = $status['error'] === 'license_key_missing'
                ? 'A commercial license key is required to run this platform.'
                : 'The configured commercial license key is not valid.';

            if ($this->enforcement() === 'enforce') {
                throw new ApiException(402, 'license_required', $message, [
                    'enforcement' => $status['enforcement'],
                    'reason' => $status['error'],
                    'purchase' => '/api/v1/license/plans',
                ]);
            }
        }

        return $status;
    }

    public function planCode(): string
    {
        return $this->app->remoteLicense()->plan();
    }

    public function feature(string $name): bool
    {
        return $this->app->remoteLicense()->feature($name);
    }

    public function requireFeature(string $name): void
    {
        $this->app->remoteLicense()->requireFeature($name);
    }

    public function enforcement(): string
    {
        $value = strtolower((string) $this->app->config()->get('licensing.enforcement', 'off'));

        return in_array($value, ['off', 'warn', 'enforce'], true) ? $value : 'off';
    }

    public function issue(string $planCode, string $email, ?string $holderName = null, ?int $durationDays = null, ?int $maxActivations = null): array
    {
        $plan = $this->plan($planCode);
        $clean = Validator::validate(['email' => $email], ['email' => 'required|string:3,190|email']);
        $id = Str::id();
        $now = Str::now();
        $days = $durationDays ?? (int) ($plan['duration_days'] ?? 365);
        $activations = $maxActivations ?? (int) ($plan['max_activations'] ?? 1);
        $expiresAt = $days > 0 ? Str::now($days * 86400) : null;
        $key = $this->generateKey($planCode);
        $privateKey = (string) $this->app->config()->get('licensing.private_key', '');

        if ($privateKey !== '') {
            $signed = $this->sign([
                'v' => 1,
                'id' => $id,
                'plan' => $planCode,
                'email' => $clean['email'],
                'holder_name' => $holderName,
                'issued_at' => time(),
                'expires_at' => $expiresAt,
                'max_activations' => $activations,
            ], $privateKey);

            if ($signed !== null) {
                $key = $signed;
            }
        }

        $this->app->db()->insert('license_keys', [
            'id' => $id,
            'key_hash' => Str::hash($key),
            'key_preview' => substr($key, 0, 16),
            'plan' => $planCode,
            'holder_name' => $holderName,
            'holder_email' => $clean['email'],
            'status' => 'active',
            'max_activations' => max(1, $activations),
            'activation_count' => 0,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'metadata' => json_encode(['features' => $plan['features'] ?? []], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'license' => $this->licensePayload($this->find($id)),
            'key' => $key,
        ];
    }

    public function verify(string $key): array
    {
        $key = trim($key);

        if ($key === '') {
            return ['valid' => false, 'error' => 'license_key_missing'];
        }

        if (str_starts_with($key, 'soc1.')) {
            return $this->verifySigned($key);
        }

        $row = $this->app->db()->fetch('SELECT * FROM license_keys WHERE key_hash = ? LIMIT 1', [Str::hash($key)]);

        if ($row === null) {
            return ['valid' => false, 'error' => 'license_key_unknown'];
        }

        return $this->verifyRow($row);
    }

    public function activate(string $key, string $installationId, ?string $domain = null, ?string $ipHash = null): array
    {
        $installationId = trim($installationId);

        if ($installationId === '' || mb_strlen($installationId, 'UTF-8') > 120) {
            throw ApiException::badRequest('Installation id must be between 1 and 120 characters.');
        }

        $verified = $this->verify($key);

        if (!$verified['valid']) {
            throw ApiException::forbidden('License key is not valid.');
        }

        $row = $this->app->db()->fetch('SELECT * FROM license_keys WHERE key_hash = ? LIMIT 1', [Str::hash(trim($key))]);

        if ($row === null) {
            return [
                'activated' => true,
                'license' => $verified,
                'installation_id' => $installationId,
                'persistent' => false,
            ];
        }

        $existing = $this->app->db()->fetch(
            'SELECT * FROM license_activations WHERE license_id = ? AND installation_id = ? LIMIT 1',
            [$row['id'], $installationId]
        );

        if ($existing === null && (int) $row['activation_count'] >= (int) $row['max_activations']) {
            throw ApiException::forbidden('This license has reached its activation limit.');
        }

        $now = Str::now();

        if ($existing === null) {
            $this->app->db()->insert('license_activations', [
                'id' => Str::id(),
                'license_id' => $row['id'],
                'installation_id' => $installationId,
                'domain' => $domain,
                'ip_hash' => $ipHash,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
            $this->app->db()->run('UPDATE license_keys SET activation_count = activation_count + 1, updated_at = ? WHERE id = ?', [$now, $row['id']]);
        } else {
            $this->app->db()->update('license_activations', [
                'last_seen_at' => $now,
                'domain' => $domain,
                'ip_hash' => $ipHash,
            ], 'id = ?', [$existing['id']]);
        }

        return [
            'activated' => true,
            'license' => $this->verify($key),
            'installation_id' => $installationId,
            'persistent' => true,
        ];
    }

    public function checkout(string $planCode, string $email, ?string $holderName = null): array
    {
        $plan = $this->plan($planCode);
        $clean = Validator::validate(['email' => $email], ['email' => 'required|string:3,190|email']);
        $id = Str::id();
        $now = Str::now();
        $provider = (string) $this->app->config()->get('payments.provider', 'manual');
        $reference = 'lic_' . strtolower(Str::token(8));
        $this->app->db()->insert('license_orders', [
            'id' => $id,
            'provider' => $provider,
            'provider_ref' => $reference,
            'plan' => $planCode,
            'amount_cents' => (int) ($plan['price_cents'] ?? 0),
            'currency' => $plan['currency'] ?? 'USD',
            'holder_name' => $holderName,
            'email' => $clean['email'],
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'order_id' => $id,
            'provider' => $provider,
            'provider_reference' => $reference,
            'plan' => $planCode,
            'amount_cents' => (int) ($plan['price_cents'] ?? 0),
            'amount' => number_format(((int) ($plan['price_cents'] ?? 0)) / 100, 2, '.', ''),
            'currency' => $plan['currency'] ?? 'USD',
            'status' => 'pending',
            'checkout_url' => null,
            'webhook' => '/api/v1/license/webhook',
            'instructions' => 'Pay the order amount and send a signed license.paid webhook with the order id to receive a license key.',
        ];
    }

    public function handleWebhook(string $rawBody, ?string $signature): array
    {
        $this->verifySignature($rawBody, $signature);
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            throw ApiException::badRequest('Webhook body must be valid JSON.');
        }

        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? '');
        $type = (string) ($payload['type'] ?? $payload['event'] ?? '');

        if (!in_array($type, ['license.paid', 'license.order.paid', 'order.paid'], true)) {
            throw ApiException::badRequest('Unsupported license webhook event.');
        }

        $orderId = (string) ($payload['data']['order_id'] ?? $payload['order_id'] ?? '');
        $reference = (string) ($payload['data']['provider_reference'] ?? $payload['provider_reference'] ?? '');
        $order = $orderId !== ''
            ? $this->app->db()->fetch('SELECT * FROM license_orders WHERE id = ? LIMIT 1', [$orderId])
            : $this->app->db()->fetch('SELECT * FROM license_orders WHERE provider_ref = ? LIMIT 1', [$reference]);

        if ($order === null) {
            throw ApiException::notFound('License order not found.');
        }

        if ($order['status'] === 'paid' && $order['license_id'] !== null) {
            return ['processed' => true, 'duplicate' => true, 'order_id' => $order['id'], 'license' => $this->licensePayload($this->find($order['license_id']))];
        }

        $issued = $this->issue($order['plan'], $order['email'], $order['holder_name'] ?? null);
        $now = Str::now();
        $this->app->db()->transaction(function () use ($order, $issued, $eventId, $type, $payload, $now): void {
            $this->app->db()->update('license_orders', [
                'status' => 'paid',
                'license_id' => $issued['license']['id'],
                'updated_at' => $now,
            ], 'id = ?', [$order['id']]);
            $this->app->db()->insert('payment_events', [
                'id' => Str::id(),
                'provider' => $order['provider'],
                'event_type' => $type,
                'provider_ref' => $eventId !== '' ? $eventId : 'license:' . $order['id'],
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'processed_at' => $now,
                'created_at' => $now,
            ]);
        });

        return [
            'processed' => true,
            'duplicate' => false,
            'order_id' => $order['id'],
            'license' => $issued['license'],
            'key' => $issued['key'],
        ];
    }

    public function forUserOrders(string $email): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT id, plan, amount_cents, currency, status, created_at FROM license_orders WHERE email = ? ORDER BY created_at DESC',
            [mb_strtolower($email, 'UTF-8')]
        );

        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'plan' => $row['plan'],
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => $row['currency'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ], $rows);
    }

    public function list(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM license_keys');
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM license_keys ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return [
            'items' => array_map(fn (array $row): array => $this->licensePayload($row), $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    public function revoke(string $licenseId): array
    {
        $row = $this->find($licenseId);

        if ($row['status'] !== 'revoked') {
            $now = Str::now();
            $this->app->db()->update('license_keys', [
                'status' => 'revoked',
                'revoked_at' => $now,
                'updated_at' => $now,
            ], 'id = ?', [$licenseId]);
        }

        return $this->licensePayload($this->find($licenseId));
    }

    public function find(string $id): array
    {
        $row = $this->app->db()->fetch('SELECT * FROM license_keys WHERE id = ? LIMIT 1', [$id]);

        if ($row === null) {
            throw ApiException::notFound('License not found.');
        }

        return $row;
    }

    private function verifyRow(array $row): array
    {
        if ($row['status'] !== 'active') {
            return ['valid' => false, 'error' => 'license_revoked', 'plan' => $row['plan']];
        }

        if ($row['expires_at'] !== null && Str::timestamp($row['expires_at']) <= time()) {
            return ['valid' => false, 'error' => 'license_expired', 'plan' => $row['plan'], 'expires_at' => $row['expires_at']];
        }

        return [
            'valid' => true,
            'error' => null,
            'id' => $row['id'],
            'plan' => $row['plan'],
            'holder_name' => $row['holder_name'],
            'holder_email' => $row['holder_email'],
            'max_activations' => (int) $row['max_activations'],
            'activation_count' => (int) $row['activation_count'],
            'issued_at' => $row['issued_at'],
            'expires_at' => $row['expires_at'],
            'features' => json_decode((string) ($row['metadata'] ?? '{}'), true)['features'] ?? [],
        ];
    }

    private function verifySigned(string $key): array
    {
        $publicKey = (string) $this->app->config()->get('licensing.public_key', '');

        if ($publicKey === '') {
            return ['valid' => false, 'error' => 'license_public_key_missing'];
        }

        $parts = explode('.', $key);

        if (count($parts) !== 3) {
            return ['valid' => false, 'error' => 'license_signature_invalid'];
        }

        $json = Str::base64UrlDecode($parts[1]);
        $signature = Str::base64UrlDecode($parts[2]);

        if ($json === false || $signature === false) {
            return ['valid' => false, 'error' => 'license_signature_invalid'];
        }

        if (openssl_verify($json, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return ['valid' => false, 'error' => 'license_signature_invalid'];
        }

        $claims = json_decode($json, true);

        if (!is_array($claims)) {
            return ['valid' => false, 'error' => 'license_payload_invalid'];
        }

        if (isset($claims['expires_at']) && Str::timestamp((string) $claims['expires_at']) <= time()) {
            return ['valid' => false, 'error' => 'license_expired', 'plan' => $claims['plan'] ?? null];
        }

        return [
            'valid' => true,
            'error' => null,
            'id' => $claims['id'] ?? null,
            'plan' => $claims['plan'] ?? null,
            'holder_name' => $claims['holder_name'] ?? null,
            'holder_email' => $claims['email'] ?? null,
            'max_activations' => (int) ($claims['max_activations'] ?? 1),
            'issued_at' => isset($claims['issued_at']) ? Str::fromTimestamp((int) $claims['issued_at']) : null,
            'expires_at' => $claims['expires_at'] ?? null,
            'features' => [],
        ];
    }

    private function sign(array $claims, string $privateKey): ?string
    {
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $signature = '';

        if ($json === false || !openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return 'soc1.' . Str::base64UrlEncode($json) . '.' . Str::base64UrlEncode($signature);
    }

    private function generateKey(string $plan): string
    {
        $groups = [];

        for ($i = 0; $i < 4; $i++) {
            $groups[] = Str::randomFrom(self::ALPHABET, 4);
        }

        return 'SOC-' . strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $plan) ?: 'LIC', 0, 4)) . '-' . implode('-', $groups);
    }

    private function verifySignature(string $rawBody, ?string $signature): void
    {
        $secret = (string) ($this->app->config()->get('licensing.webhook_secret') ?: $this->app->config()->get('payments.webhook_secret', ''));

        if ($secret === '') {
            throw ApiException::forbidden('License webhook secret is not configured.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        $provided = trim((string) $signature);

        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw ApiException::forbidden('Invalid webhook signature.');
        }
    }

    private function licensePayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'key_preview' => $row['key_preview'],
            'plan' => $row['plan'],
            'holder_name' => $row['holder_name'],
            'holder_email' => $row['holder_email'],
            'status' => $row['status'],
            'max_activations' => (int) $row['max_activations'],
            'activation_count' => (int) $row['activation_count'],
            'issued_at' => $row['issued_at'],
            'expires_at' => $row['expires_at'],
            'revoked_at' => $row['revoked_at'],
        ];
    }
}
