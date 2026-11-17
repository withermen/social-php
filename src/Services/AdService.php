<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Http\UploadedFile;
use Social\Support\Str;
use Social\Support\Validator;

final class AdService
{
    public function __construct(private readonly App $app)
    {
    }

    public function createCampaign(array $user, array $data): array
    {
        $this->app->licenses()->requireFeature('ads');
        $clean = Validator::validate($data, [
            'name' => 'required|string:1,120',
            'placement' => 'nullable|in:feed,explore,all',
            'objective' => 'nullable|in:impressions,clicks',
            'budget_cents' => 'required|integer:1,1000000000',
            'bid_cents' => 'nullable|integer:0,10000000',
            'targeting' => 'nullable|array',
            'starts_at' => 'nullable|string:0,32',
            'ends_at' => 'nullable|string:0,32',
        ]);
        $minimum = (int) $this->app->config()->get('ads.min_budget_cents', 1000);

        if ((int) $clean['budget_cents'] < $minimum) {
            throw ApiException::badRequest('Campaign budget must be at least ' . $minimum . ' cents.');
        }

        $objective = $clean['objective'] ?? 'impressions';
        $bid = (int) ($clean['bid_cents'] ?? 0);
        $defaultBid = (int) $this->app->config()->get($objective === 'clicks' ? 'ads.default_cpc_cents' : 'ads.default_cpm_cents', 0);
        $status = (bool) $this->app->config()->get('ads.require_approval', false) ? 'draft' : 'active';
        $id = Str::id();
        $now = Str::now();
        $this->app->db()->insert('ad_campaigns', [
            'id' => $id,
            'advertiser_id' => $user['id'],
            'name' => $clean['name'],
            'status' => $status,
            'placement' => $clean['placement'] ?? 'feed',
            'objective' => $objective,
            'budget_cents' => (int) $clean['budget_cents'],
            'spent_cents' => 0,
            'bid_cents' => $bid > 0 ? $bid : $defaultBid,
            'targeting' => json_encode($this->normalizeTargeting($clean['targeting'] ?? []), JSON_UNESCAPED_SLASHES),
            'starts_at' => $this->normalizeDate($clean['starts_at'] ?? null),
            'ends_at' => $this->normalizeDate($clean['ends_at'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->campaignPayload($this->findCampaign($id, $user));
    }

    public function updateCampaign(array $user, string $id, array $data): array
    {
        $campaign = $this->findCampaign($id, $user);
        $clean = Validator::validate($data, [
            'name' => 'nullable|string:1,120',
            'status' => 'nullable|in:draft,active,paused,completed',
            'placement' => 'nullable|in:feed,explore,all',
            'objective' => 'nullable|in:impressions,clicks',
            'budget_cents' => 'nullable|integer:1,1000000000',
            'bid_cents' => 'nullable|integer:0,10000000',
            'targeting' => 'nullable|array',
            'starts_at' => 'nullable|string:0,32',
            'ends_at' => 'nullable|string:0,32',
        ]);
        $update = [];

        foreach (['name', 'placement', 'objective', 'budget_cents', 'bid_cents'] as $field) {
            if (array_key_exists($field, $clean) && $clean[$field] !== null) {
                $update[$field] = $clean[$field];
            }
        }

        if (array_key_exists('targeting', $clean)) {
            $update['targeting'] = json_encode($this->normalizeTargeting($clean['targeting'] ?? []), JSON_UNESCAPED_SLASHES);
        }

        if (array_key_exists('starts_at', $clean)) {
            $update['starts_at'] = $this->normalizeDate($clean['starts_at']);
        }

        if (array_key_exists('ends_at', $clean)) {
            $update['ends_at'] = $this->normalizeDate($clean['ends_at']);
        }

        if (array_key_exists('status', $clean) && $clean['status'] !== null) {
            if ((bool) $this->app->config()->get('ads.require_approval', false)
                && $clean['status'] === 'active'
                && !in_array($user['role'], ['admin', 'moderator'], true)) {
                throw ApiException::forbidden('Campaign activation requires admin approval.');
            }

            $update['status'] = $clean['status'];
        }

        if ($update !== []) {
            $update['updated_at'] = Str::now();
            $this->app->db()->update('ad_campaigns', $update, 'id = ?', [$campaign['id']]);
        }

        return $this->campaignPayload($this->findCampaign($id, $user));
    }

    public function createCreative(array $user, string $campaignId, array $data, ?UploadedFile $image = null): array
    {
        $campaign = $this->findCampaign($campaignId, $user);
        $clean = Validator::validate($data, [
            'title' => 'required|string:1,120',
            'body' => 'nullable|string:0,500',
            'cta_label' => 'nullable|string:1,40',
            'click_url' => 'required|string:1,500|url',
            'image_url' => 'nullable|string:1,500|url',
            'video_id' => 'nullable|string:1,32',
            'weight' => 'nullable|integer:1,1000',
        ]);

        if (isset($clean['video_id']) && $clean['video_id'] !== null) {
            $this->app->videos()->findAccessible($clean['video_id'], $user['id']);
        }

        if ($image !== null && $image->isValid()) {
            $max = 5 * 1024 * 1024;

            if ($image->size > $max) {
                throw ApiException::payloadTooLarge('Ad image must be smaller than 5 MB.');
            }

            $mime = $image->detectedMime();

            if (!$this->app->media()->isImage($mime, $image->extension(), $this->app->config()->get('uploads.allowed_image'), $this->app->config()->get('uploads.allowed_image_mimes'))) {
                throw ApiException::unsupportedMedia('Ad image must be a JPEG, PNG, WebP or GIF image.');
            }
        }

        $id = Str::id();
        $now = Str::now();
        $imagePath = null;

        if ($image !== null && $image->isValid()) {
            $imagePath = 'ads/' . $id . '.jpg';
            $this->app->media()->imageCover($image->path, $this->app->storage()->path($imagePath), 1080);
        }

        $this->app->db()->insert('ad_creatives', [
            'id' => $id,
            'campaign_id' => $campaign['id'],
            'title' => $clean['title'],
            'body' => trim((string) ($clean['body'] ?? '')) ?: null,
            'cta_label' => $clean['cta_label'] ?? 'Learn more',
            'click_url' => $clean['click_url'],
            'image_path' => $imagePath,
            'image_url' => $clean['image_url'] ?? null,
            'video_id' => $clean['video_id'] ?? null,
            'status' => 'active',
            'weight' => (int) ($clean['weight'] ?? 100),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->creativePayload($this->findCreative($id));
    }

    public function campaigns(array $user, int $page, int $perPage, ?string $status = null): array
    {
        $offset = ($page - 1) * $perPage;
        $admin = in_array($user['role'], ['admin', 'moderator'], true);
        $where = $admin ? '1 = 1' : 'advertiser_id = ?';
        $params = $admin ? [] : [$user['id']];

        if ($status !== null && in_array($status, ['draft', 'active', 'paused', 'completed'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM ad_campaigns WHERE ' . $where, $params);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM ad_campaigns WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items' => array_map(fn (array $row): array => $this->campaignPayload($row), $rows),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function campaignDetail(array $user, string $id): array
    {
        $campaign = $this->findCampaign($id, $user);
        $creatives = $this->app->db()->fetchAll('SELECT * FROM ad_creatives WHERE campaign_id = ? ORDER BY created_at DESC', [$campaign['id']]);
        $payload = $this->campaignPayload($campaign);
        $payload['creatives'] = array_map(fn (array $row): array => $this->creativePayload($row), $creatives);

        return $payload;
    }

    public function stats(array $user, string $id): array
    {
        $campaign = $this->findCampaign($id, $user);
        $impressions = (int) $this->app->db()->value("SELECT COUNT(*) FROM ad_events WHERE campaign_id = ? AND type = 'impression'", [$campaign['id']]);
        $clicks = (int) $this->app->db()->value("SELECT COUNT(*) FROM ad_events WHERE campaign_id = ? AND type = 'click'", [$campaign['id']]);

        return [
            'campaign_id' => $campaign['id'],
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'spent_cents' => (int) $campaign['spent_cents'],
            'budget_cents' => (int) $campaign['budget_cents'],
            'remaining_cents' => max(0, (int) $campaign['budget_cents'] - (int) $campaign['spent_cents']),
            'status' => $campaign['status'],
        ];
    }

    public function setStatus(array $admin, string $id, string $status): array
    {
        $campaign = $this->findCampaign($id, $admin);

        if (!in_array($status, ['draft', 'active', 'paused', 'completed'], true)) {
            throw ApiException::badRequest('Invalid campaign status.');
        }

        $this->app->db()->update('ad_campaigns', ['status' => $status, 'updated_at' => Str::now()], 'id = ?', [$campaign['id']]);

        return $this->campaignPayload($this->findCampaign($id, $admin));
    }

    public function serve(?array $user, string $placement, ?string $videoId, string $ipHash): array
    {
        $placement = in_array($placement, ['feed', 'explore'], true) ? $placement : 'feed';

        if (!$this->app->licenses()->feature('ads')) {
            return ['ad' => null, 'reason' => 'plan_feature_disabled'];
        }

        if (!(bool) $this->app->config()->get('ads.enabled', true)) {
            return ['ad' => null, 'reason' => 'disabled'];
        }

        if ($user !== null && $this->app->premium()->summary($user['id'])['entitlements']['ad_free']) {
            return ['ad' => null, 'reason' => 'premium_ad_free'];
        }

        $contextTopics = [];

        if ($videoId !== null) {
            $contextTopics = $this->app->analysis()->topicsFor($videoId);
        } elseif ($user !== null) {
            foreach ($this->app->analysis()->interests($user['id'], 30) as $interest) {
                $contextTopics[$interest['topic']] = $interest['score'];
            }
        }

        $now = Str::now();
        $hourAgo = Str::now(-3600);

        if ($user !== null) {
            $frequency = (int) $this->app->db()->value(
                "SELECT COUNT(*) FROM ad_events WHERE user_id = ? AND type = 'impression' AND created_at >= ?",
                [$user['id'], $hourAgo]
            );
        } else {
            $frequency = (int) $this->app->db()->value(
                "SELECT COUNT(*) FROM ad_events WHERE user_id IS NULL AND ip_hash = ? AND type = 'impression' AND created_at >= ?",
                [$ipHash, $hourAgo]
            );
        }

        if ($frequency >= (int) $this->app->config()->get('ads.frequency_per_hour', 12)) {
            return ['ad' => null, 'reason' => 'frequency_cap'];
        }

        $rows = $this->app->db()->fetchAll(
            "SELECT * FROM ad_campaigns WHERE status = 'active' AND (placement = ? OR placement = 'all') AND spent_cents < budget_cents AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY bid_cents DESC, created_at ASC LIMIT 50",
            [$placement, $now, $now]
        );
        $eligible = [];

        foreach ($rows as $campaign) {
            $targeting = $this->decodeTargeting($campaign['targeting']);
            $topics = array_values(array_filter((array) ($targeting['topics'] ?? [])));

            if ($topics !== []) {
                $matched = false;

                foreach ($topics as $topic) {
                    if (isset($contextTopics[$topic])) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            if ($videoId !== null && isset($targeting['formats'])) {
                $video = $this->app->db()->fetch('SELECT duration FROM videos WHERE id = ?', [$videoId]);
                $format = $video === null ? null : $this->app->platform()->formatOf((int) $video['duration']);

                if ($format !== null && !in_array($format, (array) $targeting['formats'], true)) {
                    continue;
                }
            }

            $eligible[] = $campaign;
        }

        if ($eligible === []) {
            return ['ad' => null, 'reason' => 'no_fill'];
        }

        $campaign = $eligible[array_rand($eligible)];
        $creatives = $this->app->db()->fetchAll("SELECT * FROM ad_creatives WHERE campaign_id = ? AND status = 'active'", [$campaign['id']]);

        if ($creatives === []) {
            return ['ad' => null, 'reason' => 'no_creative'];
        }

        $weighted = [];

        foreach ($creatives as $creative) {
            $weighted = array_merge($weighted, array_fill(0, max(1, (int) $creative['weight']), $creative));
        }

        $creative = $weighted[array_rand($weighted)];
        $cost = $this->costFor($campaign, 'impression');
        $eventId = Str::id();
        $this->recordEvent($campaign, $creative, $user['id'] ?? null, 'impression', $placement, $ipHash, $cost, $eventId);
        $ad = $this->creativePayload($creative);
        $ad['placement'] = $placement;
        $ad['sponsored'] = true;
        $ad['impression_id'] = $eventId;

        return ['ad' => $ad, 'reason' => null];
    }

    public function click(?array $user, string $creativeId, string $ipHash): array
    {
        $creative = $this->findCreative($creativeId);
        $campaign = $this->app->db()->fetch('SELECT * FROM ad_campaigns WHERE id = ? LIMIT 1', [$creative['campaign_id']]);

        if ($campaign === null || $campaign['status'] !== 'active') {
            throw ApiException::notFound('Advertisement is not available.');
        }

        $cost = $this->costFor($campaign, 'click');
        $eventId = Str::id();
        $this->recordEvent($campaign, $creative, $user['id'] ?? null, 'click', null, $ipHash, $cost, $eventId);

        return [
            'clicked' => true,
            'click_url' => $creative['click_url'],
            'campaign_id' => $campaign['id'],
            'creative_id' => $creative['id'],
            'event_id' => $eventId,
        ];
    }

    private function recordEvent(array $campaign, array $creative, ?string $userId, string $type, ?string $placement, string $ipHash, int $cost, string $eventId): void
    {
        $now = Str::now();
        $this->app->db()->transaction(function () use ($campaign, $creative, $userId, $type, $placement, $ipHash, $cost, $eventId, $now): void {
            $this->app->db()->insert('ad_events', [
                'id' => $eventId,
                'campaign_id' => $campaign['id'],
                'creative_id' => $creative['id'],
                'user_id' => $userId,
                'type' => $type,
                'placement' => $placement,
                'ip_hash' => $userId === null ? $ipHash : null,
                'cost_cents' => $cost,
                'created_at' => $now,
            ]);

            if ($cost > 0) {
                $this->app->db()->run(
                    'UPDATE ad_campaigns SET spent_cents = spent_cents + ?, updated_at = ? WHERE id = ?',
                    [$cost, $now, $campaign['id']]
                );
                $this->app->db()->run(
                    "UPDATE ad_campaigns SET status = 'completed', updated_at = ? WHERE id = ? AND spent_cents >= budget_cents",
                    [$now, $campaign['id']]
                );
            }
        });
    }

    private function costFor(array $campaign, string $type): int
    {
        $bid = (int) $campaign['bid_cents'];

        if ($type === 'click') {
            return $campaign['objective'] === 'clicks' ? $bid : 0;
        }

        if ($campaign['objective'] === 'impressions') {
            return $bid > 0 ? max(1, (int) round($bid / 1000)) : 0;
        }

        return 0;
    }

    private function findCampaign(string $id, array $user): array
    {
        $row = $this->app->db()->fetch('SELECT * FROM ad_campaigns WHERE id = ? LIMIT 1', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Ad campaign not found.');
        }

        if ($row['advertiser_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'moderator'], true)) {
            throw ApiException::forbidden('You cannot access this ad campaign.');
        }

        return $row;
    }

    private function findCreative(string $id): array
    {
        $row = $this->app->db()->fetch('SELECT * FROM ad_creatives WHERE id = ? LIMIT 1', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Advertisement not found.');
        }

        return $row;
    }

    private function campaignPayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'advertiser_id' => $row['advertiser_id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'placement' => $row['placement'],
            'objective' => $row['objective'],
            'budget_cents' => (int) $row['budget_cents'],
            'spent_cents' => (int) $row['spent_cents'],
            'bid_cents' => (int) $row['bid_cents'],
            'targeting' => $this->decodeTargeting($row['targeting']),
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function creativePayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'campaign_id' => $row['campaign_id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'cta_label' => $row['cta_label'],
            'click_url' => $row['click_url'],
            'image_url' => $row['image_url'] ?: ($row['image_path'] ? $this->app->assetUrl($row['image_path']) : null),
            'video_id' => $row['video_id'],
            'status' => $row['status'],
            'weight' => (int) $row['weight'],
        ];
    }

    private function normalizeTargeting(array $targeting): array
    {
        $topics = [];

        foreach ((array) ($targeting['topics'] ?? []) as $topic) {
            if (is_scalar($topic)) {
                $topic = Str::normalizeTag((string) $topic);

                if ($topic !== '') {
                    $topics[$topic] = true;
                }
            }
        }

        $formats = array_values(array_intersect((array) ($targeting['formats'] ?? []), ['short', 'long']));

        return [
            'topics' => array_slice(array_keys($topics), 0, 20),
            'formats' => $formats,
        ];
    }

    private function decodeTargeting(?string $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : ['topics' => [], 'formats' => []];
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw ApiException::badRequest('Invalid campaign date.');
        }

        return Str::fromTimestamp($timestamp);
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }
}
