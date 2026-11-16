<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;
use Social\Support\Validator;

final class PremiumService
{
    private array $cache = [];
    private bool $seeded = false;

    public function __construct(private readonly App $app)
    {
    }

    public function plans(): array
    {
        $this->ensurePlans();
        $rows = $this->app->db()->fetchAll("SELECT * FROM premium_plans WHERE status = 'active' ORDER BY sort_order ASC, price_cents ASC");

        return array_map(fn (array $row): array => $this->planPayload($row), $rows);
    }

    public function ensurePlans(): void
    {
        if ($this->seeded) {
            return;
        }

        $this->seeded = true;
        $now = Str::now();

        foreach ((array) $this->app->config()->get('premium.plans', []) as $code => $plan) {
            $existing = $this->app->db()->fetch('SELECT id FROM premium_plans WHERE code = ? LIMIT 1', [$code]);

            if ($existing !== null) {
                continue;
            }

            $this->app->db()->insert('premium_plans', [
                'id' => Str::id(),
                'code' => $code,
                'name' => $plan['name'] ?? ucfirst($code),
                'description' => $plan['description'] ?? null,
                'price_cents' => (int) ($plan['price_cents'] ?? 0),
                'currency' => $plan['currency'] ?? 'USD',
                'interval' => $plan['interval'] ?? 'month',
                'features' => json_encode(array_values((array) ($plan['features'] ?? [])), JSON_UNESCAPED_SLASHES),
                'limits' => json_encode((array) ($plan['limits'] ?? []), JSON_UNESCAPED_SLASHES),
                'status' => 'active',
                'sort_order' => (int) ($plan['sort_order'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function summary(string $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $subscription = $this->activeSubscription($userId);
        $entitlements = $this->baseEntitlements();

        if ($subscription !== null) {
            $plan = json_decode((string) ($subscription['features'] ?? '[]'), true);
            $limits = json_decode((string) ($subscription['limits'] ?? '{}'), true);

            foreach ((array) $plan as $feature) {
                $entitlements[$feature] = true;
            }

            foreach ((array) $limits as $key => $value) {
                $entitlements[$key] = $value;
            }
        }

        $summary = [
            'is_premium' => $subscription !== null,
            'plan' => $subscription === null ? null : $subscription['plan_code'],
            'plan_name' => $subscription === null ? null : $subscription['plan_name'],
            'status' => $subscription['status'] ?? 'free',
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            'entitlements' => $entitlements,
        ];

        return $this->cache[$userId] = $summary;
    }

    public function entitlements(string $userId): array
    {
        return $this->summary($userId)['entitlements'];
    }

    public function activeSubscription(string $userId): ?array
    {
        $row = $this->app->db()->fetch(
            "SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.features, p.limits, p.price_cents, p.currency, p.interval
             FROM subscriptions s JOIN premium_plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status = 'active'
             ORDER BY s.created_at DESC LIMIT 1",
            [$userId]
        );

        if ($row === null) {
            return null;
        }

        if ($row['current_period_end'] !== null && Str::timestamp($row['current_period_end']) <= time()) {
            $this->app->db()->update('subscriptions', [
                'status' => 'expired',
                'updated_at' => Str::now(),
            ], 'id = ?', [$row['id']]);

            return null;
        }

        return $row;
    }

    public function subscribe(array $user, string $planCode): array
    {
        if (!(bool) $this->app->config()->get('premium.allow_direct_subscribe', true)) {
            throw ApiException::forbidden('Direct subscriptions are disabled. Use the payment checkout flow.');
        }

        return $this->activate($user['id'], $planCode, 'internal', null);
    }

    public function activate(string $userId, string $planCode, string $provider = 'internal', ?string $providerRef = null, ?string $periodEnd = null): array
    {
        $this->ensurePlans();
        $plan = $this->app->db()->fetch("SELECT * FROM premium_plans WHERE code = ? AND status = 'active' LIMIT 1", [$planCode]);

        if ($plan === null) {
            throw ApiException::notFound('Premium plan not found.');
        }

        $now = Str::now();
        $end = $periodEnd ?? Str::now($plan['interval'] === 'year' ? 365 * 86400 : 30 * 86400);
        $id = Str::id();
        $this->app->db()->transaction(function () use ($userId, $plan, $provider, $providerRef, $now, $end, $id): void {
            $this->app->db()->update('subscriptions', [
                'status' => 'canceled',
                'canceled_at' => $now,
                'updated_at' => $now,
            ], "user_id = ? AND status = 'active'", [$userId]);
            $this->app->db()->insert('subscriptions', [
                'id' => $id,
                'user_id' => $userId,
                'plan_id' => $plan['id'],
                'status' => 'active',
                'provider' => $provider,
                'provider_ref' => $providerRef,
                'started_at' => $now,
                'current_period_end' => $end,
                'cancel_at_period_end' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        unset($this->cache[$userId]);

        return [
            'subscription' => $this->subscriptionPayload($this->findSubscription($id)),
            'premium' => $this->summary($userId),
        ];
    }

    public function cancel(array $user): array
    {
        $subscription = $this->activeSubscription($user['id']);

        if ($subscription === null) {
            throw ApiException::badRequest('You do not have an active premium subscription.');
        }

        $now = Str::now();
        $this->app->db()->update('subscriptions', [
            'status' => 'canceled',
            'cancel_at_period_end' => 1,
            'canceled_at' => $now,
            'updated_at' => $now,
        ], 'id = ?', [$subscription['id']]);
        unset($this->cache[$user['id']]);

        return [
            'canceled' => true,
            'subscription' => $this->subscriptionPayload($this->findSubscription($subscription['id'])),
            'premium' => $this->summary($user['id']),
        ];
    }

    public function adminGrant(array $admin, string $userId, string $planCode, ?int $days = null): array
    {
        $end = $days !== null && $days > 0 ? Str::now($days * 86400) : null;
        $result = $this->activate($userId, $planCode, 'admin', 'grant:' . $admin['id'], $end);
        $result['granted_by'] = $admin['id'];

        return $result;
    }

    public function handleWebhook(string $rawBody, ?string $signature): array
    {
        $secret = (string) $this->app->config()->get('payments.webhook_secret', '');

        if ($secret === '') {
            throw ApiException::forbidden('Payment webhook secret is not configured.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        if ($signature === null || !hash_equals($expected, trim($signature))) {
            throw ApiException::forbidden('Invalid webhook signature.');
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            throw ApiException::badRequest('Webhook body must be valid JSON.');
        }

        $type = (string) ($payload['type'] ?? $payload['event'] ?? '');
        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        if ($type === 'subscription.activated' || $type === 'invoice.paid') {
            $userId = (string) ($data['user_id'] ?? '');
            $email = (string) ($data['email'] ?? '');
            $planCode = (string) ($data['plan_code'] ?? $data['plan'] ?? '');

            if ($userId === '' && $email !== '') {
                $user = $this->app->db()->fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [mb_strtolower($email, 'UTF-8')]);
                $userId = (string) ($user['id'] ?? '');
            }

            if ($userId === '' || $planCode === '') {
                throw ApiException::badRequest('Webhook data must contain user_id and plan_code.');
            }

            $this->app->users()->requireById($userId);
            $result = $this->activate($userId, $planCode, (string) ($payload['provider'] ?? 'webhook'), (string) ($data['provider_ref'] ?? $eventId ?: null), $data['period_end'] ?? null);
        } elseif ($type === 'subscription.canceled') {
            $reference = (string) ($data['provider_ref'] ?? $data['subscription_id'] ?? '');
            $userId = (string) ($data['user_id'] ?? '');
            $subscription = $reference !== ''
                ? $this->app->db()->fetch('SELECT * FROM subscriptions WHERE provider_ref = ? ORDER BY created_at DESC LIMIT 1', [$reference])
                : ($userId !== '' ? $this->activeSubscription($userId) : null);

            if ($subscription === null) {
                throw ApiException::notFound('Subscription not found.');
            }

            $now = Str::now();
            $this->app->db()->update('subscriptions', [
                'status' => 'canceled',
                'cancel_at_period_end' => 1,
                'canceled_at' => $now,
                'updated_at' => $now,
            ], 'id = ?', [$subscription['id']]);
            unset($this->cache[$subscription['user_id']]);
            $result = [
                'canceled' => true,
                'subscription' => $this->subscriptionPayload($this->findSubscription($subscription['id'])),
                'premium' => $this->summary($subscription['user_id']),
            ];
        } else {
            throw ApiException::badRequest('Unsupported subscription webhook event.');
        }

        $now = Str::now();
        $this->app->db()->insert('payment_events', [
            'id' => Str::id(),
            'provider' => (string) ($payload['provider'] ?? 'webhook'),
            'event_type' => $type,
            'provider_ref' => $eventId !== '' ? $eventId : Str::id(),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'processed_at' => $now,
            'created_at' => $now,
        ]);

        return ['processed' => true, 'event_type' => $type] + $result;
    }

    public function planByCode(string $code): array
    {
        $this->ensurePlans();
        $row = $this->app->db()->fetch("SELECT * FROM premium_plans WHERE code = ? AND status = 'active' LIMIT 1", [$code]);

        if ($row === null) {
            throw ApiException::notFound('Premium plan not found.');
        }

        return $this->planPayload($row);
    }

    private function baseEntitlements(): array
    {
        return [
            'ad_free' => false,
            'premium_badge' => false,
            'larger_uploads' => false,
            'longer_videos' => false,
            'creator_analytics' => false,
            'priority_delivery' => false,
            'max_upload_size' => (int) $this->app->config()->get('uploads.max_size', 536870912),
            'max_duration' => (int) $this->app->config()->get('uploads.max_duration', 600),
        ];
    }

    private function planPayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => $row['description'],
            'price_cents' => (int) $row['price_cents'],
            'price' => number_format((int) $row['price_cents'] / 100, 2, '.', ''),
            'currency' => $row['currency'],
            'interval' => $row['interval'],
            'features' => json_decode((string) ($row['features'] ?? '[]'), true),
            'limits' => json_decode((string) ($row['limits'] ?? '{}'), true),
        ];
    }

    private function subscriptionPayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'plan_code' => $row['plan_code'] ?? null,
            'plan_name' => $row['plan_name'] ?? null,
            'status' => $row['status'],
            'provider' => $row['provider'],
            'started_at' => $row['started_at'],
            'current_period_end' => $row['current_period_end'],
            'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'canceled_at' => $row['canceled_at'],
        ];
    }

    private function findSubscription(string $id): array
    {
        $row = $this->app->db()->fetch(
            'SELECT s.*, p.code AS plan_code, p.name AS plan_name FROM subscriptions s JOIN premium_plans p ON p.id = s.plan_id WHERE s.id = ? LIMIT 1',
            [$id]
        );

        if ($row === null) {
            throw ApiException::notFound('Subscription not found.');
        }

        return $row;
    }
}
