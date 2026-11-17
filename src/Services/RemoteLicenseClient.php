<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;

final class RemoteLicenseClient
{
    private bool $refreshed = false;

    public function __construct(private readonly App $app)
    {
    }

    public function plan(): string
    {
        $plan = strtolower(trim((string) $this->app->config()->get('licensing.plan', 'commercial')));

        return in_array($plan, ['free', 'commercial'], true) ? $plan : 'commercial';
    }

    public function isFree(): bool
    {
        return $this->plan() === 'free';
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->app->config()->get('licensing.mode', 'local')));

        return in_array($mode, ['off', 'local', 'remote'], true) ? $mode : 'local';
    }

    public function remoteRequired(): bool
    {
        return $this->plan() === 'commercial' && $this->mode() === 'remote';
    }

    public function features(): array
    {
        if ($this->isFree()) {
            return [
                'ads' => false,
                'advanced_recommendations' => false,
                'short_video' => false,
                'premium' => true,
                'commercial_use' => false,
            ];
        }

        if ($this->mode() === 'remote') {
            $state = $this->state();
            $valid = $state !== null && $state['status'] === 'valid';

            return [
                'ads' => $valid,
                'advanced_recommendations' => $valid,
                'short_video' => $valid,
                'premium' => $valid,
                'commercial_use' => $valid,
            ];
        }

        return [
            'ads' => true,
            'advanced_recommendations' => true,
            'short_video' => true,
            'premium' => true,
            'commercial_use' => true,
        ];
    }

    public function feature(string $name): bool
    {
        return (bool) ($this->features()[$name] ?? false);
    }

    public function requireFeature(string $name): void
    {
        if ($this->feature($name)) {
            return;
        }

        throw new ApiException(402, 'license_required', 'This feature is not available on the current license plan.', [
            'feature' => $name,
            'plan' => $this->plan(),
            'purchase' => '/api/v1/license/plans',
        ]);
    }

    public function state(): ?array
    {
        $row = $this->app->db()->fetch("SELECT * FROM license_state WHERE id = 'current' LIMIT 1");

        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);

        return [
            'plan' => $row['plan'],
            'status' => $row['status'],
            'license_id' => $row['license_id'],
            'domain' => $row['domain'],
            'installation_id' => $row['installation_id'],
            'verified_at' => $row['verified_at'],
            'expires_at' => $row['expires_at'],
            'next_check_at' => $row['next_check_at'],
            'error' => $row['error'],
            'failures' => (int) $row['failures'],
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    public function status(): array
    {
        $plan = $this->plan();
        $mode = $this->mode();
        $state = $this->state();
        $required = $plan === 'commercial' && $mode === 'remote';
        $valid = true;

        if ($required) {
            $valid = $state !== null
                && $state['status'] === 'valid'
                && ($state['expires_at'] === null || strtotime((string) $state['expires_at']) > time());
        }

        return [
            'plan' => $plan,
            'mode' => $mode,
            'required' => $required,
            'valid' => $valid,
            'status' => $state['status'] ?? ($required ? 'missing' : 'inactive'),
            'license_id' => $state['license_id'] ?? null,
            'domain' => $state['domain'] ?? $this->domain(),
            'installation_id' => $state['installation_id'] ?? $this->installationId(),
            'verified_at' => $state['verified_at'] ?? null,
            'expires_at' => $state['expires_at'] ?? null,
            'next_check_at' => $state['next_check_at'] ?? null,
            'failures' => $state['failures'] ?? 0,
            'error' => $state['error'] ?? null,
            'payload' => $state['payload'] ?? [],
            'features' => $this->features(),
        ];
    }

    public function guard(): array
    {
        if (!$this->remoteRequired()) {
            return $this->status();
        }

        $state = $this->state();
        $now = time();
        $maxFailures = max(1, (int) $this->app->config()->get('licensing.max_failures', 5));
        $grace = max(0, (int) $this->app->config()->get('licensing.grace_hours', 72)) * 3600;

        if ($this->isUsable($state, $now)) {
            $nextCheck = $state['next_check_at'] === null ? 0 : (int) strtotime((string) $state['next_check_at']);

            if ($nextCheck > $now && !$this->expired($state, $now)) {
                return $this->status();
            }
        }

        $refresh = $this->refresh();

        if ($refresh['status'] === 'valid') {
            return $this->status();
        }

        $verifiedAt = $state === null || $state['verified_at'] === null ? 0 : (int) strtotime((string) $state['verified_at']);
        $withinGrace = $state !== null && $verifiedAt > 0 && $verifiedAt + $grace >= $now;

        if ($state !== null
            && $withinGrace
            && $this->isUsable($state, $now)
            && ($state['failures'] ?? 0) < $maxFailures
            && !$this->expired($state, $now)) {
            $status = $this->status();
            $status['stale'] = true;

            return $status;
        }

        $message = $refresh['error'] === 'license_key_missing'
            ? 'A commercial license key is required to run this platform.'
            : 'The commercial license could not be verified. The platform is locked until activation.';

        throw new ApiException(402, 'license_required', $message, [
            'reason' => $refresh['error'],
            'plan' => $this->plan(),
            'failures' => $refresh['failures'],
            'purchase' => '/api/v1/license/plans',
            'activate' => '/api/v1/license/activate',
        ]);
    }

    public function refresh(bool $force = false): array
    {
        if ($this->refreshed && !$force) {
            return $this->status();
        }

        $this->refreshed = true;

        if (!$this->remoteRequired()) {
            return $this->status();
        }

        $key = trim((string) $this->app->config()->get('licensing.key', ''));

        if ($key === '') {
            return $this->failure('license_key_missing');
        }

        $server = trim((string) $this->app->config()->get('licensing.server_url', ''));
        $publicKey = trim((string) $this->app->config()->get('licensing.server_public_key', ''));

        if ($server === '' || $publicKey === '') {
            return $this->failure('license_server_not_configured');
        }

        $domain = $this->domain();

        if ($domain === '') {
            return $this->failure('license_domain_missing');
        }

        $action = 'activate';
        $nonce = bin2hex(random_bytes(24));
        $response = $this->post($server . '/' . $action, [
            'key' => $key,
            'domain' => $domain,
            'installation_id' => $this->installationId(),
            'nonce' => $nonce,
        ]);

        if ($response === null) {
            return $this->failure('license_server_unreachable');
        }

        if (!$this->verifySignature($response, $publicKey)) {
            return $this->failure('license_signature_invalid');
        }

        if (($response['nonce'] ?? '') !== $nonce) {
            return $this->failure('license_nonce_mismatch');
        }

        if (abs(time() - (int) ($response['ts'] ?? 0)) > 300) {
            return $this->failure('license_timestamp_invalid');
        }

        if (!(bool) ($response['ok'] ?? false)) {
            return $this->failure((string) ($response['error'] ?? 'license_invalid'));
        }

        $data = $response['data'] ?? null;

        if (!is_array($data) || !(bool) ($data['valid'] ?? false)) {
            return $this->failure('license_invalid');
        }

        if (isset($data['domain']) && strtolower((string) $data['domain']) !== strtolower($domain)) {
            return $this->failure('license_domain_mismatch');
        }

        if (isset($data['installation_id']) && (string) $data['installation_id'] !== $this->installationId()) {
            return $this->failure('license_installation_mismatch');
        }

        $now = time();
        $interval = max(60, (int) $this->app->config()->get('licensing.check_interval', (int) ($data['next_check_seconds'] ?? 21600)));
        $this->store([
            'id' => 'current',
            'plan' => $this->plan(),
            'status' => 'valid',
            'license_id' => $data['license_id'] ?? null,
            'domain' => $domain,
            'installation_id' => $this->installationId(),
            'verified_at' => Str::fromTimestamp($now),
            'expires_at' => $data['expires_at'] ?? null,
            'next_check_at' => Str::fromTimestamp($now + $interval),
            'payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => (string) ($response['sig'] ?? ''),
            'error' => null,
            'failures' => 0,
            'updated_at' => Str::now(),
        ]);

        return $this->status();
    }

    public function activate(string $key): array
    {
        $key = trim($key);

        if ($key === '') {
            throw new ApiException(422, 'validation_error', 'License key is required.');
        }

        $server = trim((string) $this->app->config()->get('licensing.server_url', ''));
        $publicKey = trim((string) $this->app->config()->get('licensing.server_public_key', ''));
        $domain = $this->domain();

        if ($server === '' || $publicKey === '' || $domain === '') {
            throw new ApiException(422, 'license_configuration_error', 'License server, public key and domain must be configured.');
        }

        $nonce = bin2hex(random_bytes(24));
        $response = $this->post($server . '/activate', [
            'key' => $key,
            'domain' => $domain,
            'installation_id' => $this->installationId(),
            'nonce' => $nonce,
        ]);

        if ($response === null || !$this->verifySignature($response, $publicKey)) {
            throw new ApiException(502, 'license_server_invalid', 'License server response could not be verified.');
        }

        if (!(bool) ($response['ok'] ?? false)) {
            throw new ApiException(403, 'license_activation_failed', (string) ($response['message'] ?? 'License activation failed.'));
        }

        $data = $response['data'] ?? null;

        if (!is_array($data) || !(bool) ($data['valid'] ?? false)) {
            throw new ApiException(403, 'license_activation_failed', 'License activation was rejected.');
        }

        $now = time();
        $interval = max(60, (int) $this->app->config()->get('licensing.check_interval', (int) ($data['next_check_seconds'] ?? 21600)));
        $this->store([
            'id' => 'current',
            'plan' => $this->plan(),
            'status' => 'valid',
            'license_id' => $data['license_id'] ?? null,
            'domain' => $domain,
            'installation_id' => $this->installationId(),
            'verified_at' => Str::fromTimestamp($now),
            'expires_at' => $data['expires_at'] ?? null,
            'next_check_at' => Str::fromTimestamp($now + $interval),
            'payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => (string) ($response['sig'] ?? ''),
            'error' => null,
            'failures' => 0,
            'updated_at' => Str::now(),
        ]);

        return $this->status();
    }

    public function domain(): string
    {
        $domain = strtolower(trim((string) $this->app->config()->get('licensing.domain', '')));

        if ($domain === '') {
            $host = parse_url((string) $this->app->config()->get('app.url', ''), PHP_URL_HOST);
            $domain = is_string($host) ? strtolower($host) : '';
        }

        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $domain = ltrim($domain, '.');

        return (string) (preg_replace('/^www\./', '', $domain) ?? $domain);
    }

    public function installationId(): string
    {
        $configured = trim((string) $this->app->config()->get('licensing.installation_id', ''));

        if ($configured !== '') {
            return mb_substr($configured, 0, 120, 'UTF-8');
        }

        $seed = (string) $this->app->config()->get('app.key', 'socialphp')
            . '|'
            . (string) $this->app->config()->get('database.database', 'social')
            . '|socialphp-license';

        return 'inst_' . substr(hash('sha256', $seed), 0, 40);
    }

    private function isUsable(?array $state, int $now): bool
    {
        if ($state === null || $state['status'] !== 'valid') {
            return false;
        }

        if ($this->expired($state, $now)) {
            return false;
        }

        return $state['verified_at'] !== null;
    }

    private function expired(array $state, int $now): bool
    {
        return $state['expires_at'] !== null && (int) strtotime((string) $state['expires_at']) <= $now;
    }

    private function failure(string $error): array
    {
        $state = $this->state();
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $maxFailures = max(1, (int) $this->app->config()->get('licensing.max_failures', 5));
        $status = $failures >= $maxFailures ? 'locked' : 'stale';
        $this->store([
            'id' => 'current',
            'plan' => $this->plan(),
            'status' => $status,
            'license_id' => $state['license_id'] ?? null,
            'domain' => $this->domain(),
            'installation_id' => $this->installationId(),
            'verified_at' => $state['verified_at'] ?? null,
            'expires_at' => $state['expires_at'] ?? null,
            'next_check_at' => $state['next_check_at'] ?? null,
            'payload' => json_encode($state['payload'] ?? [], JSON_UNESCAPED_SLASHES),
            'signature' => null,
            'error' => $error,
            'failures' => $failures,
            'updated_at' => Str::now(),
        ]);

        $result = $this->status();
        $result['error'] = $error;
        $result['failures'] = $failures;

        return $result;
    }

    private function store(array $data): void
    {
        $existing = $this->app->db()->fetch("SELECT id FROM license_state WHERE id = 'current' LIMIT 1");

        if ($existing === null) {
            $this->app->db()->insert('license_state', $data);

            return;
        }

        unset($data['id']);
        $this->app->db()->update('license_state', $data, "id = 'current'");
    }

    private function verifySignature(array $response, string $publicKey): bool
    {
        $signature = (string) ($response['sig'] ?? '');

        if ($signature === '') {
            return false;
        }

        $pem = str_contains($publicKey, 'BEGIN PUBLIC KEY') ? $publicKey : base64_decode($publicKey, true);

        if ($pem === false || $pem === '') {
            return false;
        }

        $payload = [
            'data' => $response['data'] ?? null,
            'ok' => (bool) ($response['ok'] ?? false),
            'error' => $response['error'] ?? null,
            'message' => $response['message'] ?? null,
            'ts' => (int) ($response['ts'] ?? 0),
            'nonce' => (string) ($response['nonce'] ?? ''),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = $this->base64UrlDecode($signature);

        if ($json === false || $decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($json, $decoded, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function post(string $url, array $body): ?array
    {
        $timeout = max(1, (int) $this->app->config()->get('licensing.timeout', 10));
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return null;
        }

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            if (!is_string($result) || $result === '' || $status >= 500) {
                return null;
            }

            $decoded = json_decode($result, true);

            return is_array($decoded) ? $decoded : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);

        if (!is_string($result) || $result === '') {
            return null;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
