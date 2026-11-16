<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;
use Social\Support\Validator;

final class AdminService
{
    public function __construct(private readonly App $app)
    {
    }

    public function stats(): array
    {
        $db = $this->app->db();

        return [
            'users' => [
                'total' => (int) $db->value('SELECT COUNT(*) FROM users'),
                'active' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
                'banned' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'banned'"),
                'new_7d' => (int) $db->value('SELECT COUNT(*) FROM users WHERE created_at >= ?', [Str::now(-604800)]),
            ],
            'videos' => [
                'total' => (int) $db->value('SELECT COUNT(*) FROM videos'),
                'published' => (int) $db->value("SELECT COUNT(*) FROM videos WHERE status = 'published'"),
                'removed' => (int) $db->value("SELECT COUNT(*) FROM videos WHERE status = 'removed'"),
                'new_7d' => (int) $db->value('SELECT COUNT(*) FROM videos WHERE created_at >= ?', [Str::now(-604800)]),
            ],
            'engagement' => [
                'views' => (int) $db->value('SELECT COALESCE(SUM(view_count), 0) FROM videos'),
                'likes' => (int) $db->value('SELECT COALESCE(SUM(like_count), 0) FROM videos'),
                'comments' => (int) $db->value('SELECT COALESCE(SUM(comment_count), 0) FROM videos'),
                'shares' => (int) $db->value('SELECT COALESCE(SUM(share_count), 0) FROM videos'),
                'saves' => (int) $db->value('SELECT COALESCE(SUM(save_count), 0) FROM videos'),
            ],
            'storage' => [
                'video_bytes' => (int) $db->value('SELECT COALESCE(SUM(size), 0) FROM videos'),
            ],
            'reports' => [
                'open' => (int) $db->value("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
                'total' => (int) $db->value('SELECT COUNT(*) FROM reports'),
            ],
            'analysis' => [
                'analyzed' => (int) $db->value("SELECT COUNT(*) FROM video_analysis WHERE status = 'complete'"),
                'with_audio' => (int) $db->value('SELECT COUNT(*) FROM video_analysis WHERE has_audio = 1'),
                'topics' => (int) $db->value('SELECT COUNT(DISTINCT topic) FROM video_topics'),
            ],
            'ads' => [
                'campaigns' => (int) $db->value('SELECT COUNT(*) FROM ad_campaigns'),
                'active' => (int) $db->value("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'active'"),
                'impressions' => (int) $db->value("SELECT COUNT(*) FROM ad_events WHERE type = 'impression'"),
                'clicks' => (int) $db->value("SELECT COUNT(*) FROM ad_events WHERE type = 'click'"),
                'spend_cents' => (int) $db->value('SELECT COALESCE(SUM(spent_cents), 0) FROM ad_campaigns'),
            ],
            'premium' => [
                'active_subscriptions' => (int) $db->value("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'"),
                'plans' => (int) $db->value("SELECT COUNT(*) FROM premium_plans WHERE status = 'active'"),
                'mrr_cents' => (int) $db->value("SELECT COALESCE(SUM(p.price_cents), 0) FROM subscriptions s JOIN premium_plans p ON p.id = s.plan_id WHERE s.status = 'active'"),
            ],
            'licensing' => [
                'active' => (int) $db->value("SELECT COUNT(*) FROM license_keys WHERE status = 'active'"),
                'total' => (int) $db->value('SELECT COUNT(*) FROM license_keys'),
                'revenue_cents' => (int) $db->value("SELECT COALESCE(SUM(amount_cents), 0) FROM license_orders WHERE status = 'paid'"),
            ],
        ];
    }

    public function users(string $query, string $status, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $where = '1 = 1';
        $params = [];

        if ($query !== '') {
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where .= " AND (username LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!')";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (in_array($status, ['active', 'banned', 'deleted'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM users WHERE ' . $where, $params);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM users WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        $items = array_map(fn (array $row): array => [
            'id' => $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'avatar_url' => $row['avatar_path'] ? $this->app->assetUrl($row['avatar_path']) : null,
            'role' => $row['role'],
            'status' => $row['status'],
            'verified' => (bool) $row['verified'],
            'follower_count' => (int) $row['follower_count'],
            'video_count' => (int) $row['video_count'],
            'created_at' => $row['created_at'],
        ], $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    public function ban(array $admin, string $userId): array
    {
        $target = $this->app->users()->requireById($userId);

        if ($target['id'] === $admin['id']) {
            throw ApiException::badRequest('You cannot ban yourself.');
        }

        if ($target['role'] === 'admin') {
            throw ApiException::forbidden('Admin accounts cannot be banned from the API.');
        }

        $this->app->db()->update('users', ['status' => 'banned', 'updated_at' => Str::now()], 'id = ?', [$target['id']]);
        $this->app->auth()->revokeRefreshTokens($target['id']);

        return $this->userPayload($target['id']);
    }

    public function unban(string $userId): array
    {
        $target = $this->app->users()->requireById($userId);

        if ($target['status'] === 'deleted') {
            throw ApiException::badRequest('Deleted accounts cannot be restored.');
        }

        $this->app->db()->update('users', ['status' => 'active', 'updated_at' => Str::now()], 'id = ?', [$target['id']]);

        return $this->userPayload($target['id']);
    }

    public function verify(string $userId, bool $verified): array
    {
        $this->app->users()->requireById($userId);
        $this->app->db()->update('users', ['verified' => $verified ? 1 : 0, 'updated_at' => Str::now()], 'id = ?', [$userId]);

        return $this->userPayload($userId);
    }

    public function setRole(array $admin, string $userId, string $role): array
    {
        $target = $this->app->users()->requireById($userId);

        if ($target['id'] === $admin['id']) {
            throw ApiException::badRequest('You cannot change your own role.');
        }

        $clean = Validator::validate(['role' => $role], ['role' => 'required|in:user,moderator,admin']);

        if ($target['role'] === 'admin' && $clean['role'] !== 'admin') {
            throw ApiException::forbidden('Admin accounts cannot be demoted from the API.');
        }

        $this->app->db()->update('users', ['role' => $clean['role'], 'updated_at' => Str::now()], 'id = ?', [$target['id']]);

        return $this->userPayload($target['id']);
    }

    public function takedown(string $videoId): array
    {
        $row = $this->app->db()->fetch('SELECT * FROM videos WHERE id = ?', [$videoId]);

        if ($row === null) {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['status'] === 'published') {
            $this->app->db()->transaction(function () use ($row): void {
                $this->app->db()->update('videos', ['status' => 'removed', 'updated_at' => Str::now()], 'id = ?', [$row['id']]);
                $this->app->db()->run(
                    'UPDATE users SET video_count = CASE WHEN video_count > 0 THEN video_count - 1 ELSE 0 END WHERE id = ?',
                    [$row['user_id']]
                );
            });
        }

        return ['video_id' => $row['id'], 'status' => 'removed'];
    }

    public function restore(string $videoId): array
    {
        $row = $this->app->db()->fetch('SELECT * FROM videos WHERE id = ?', [$videoId]);

        if ($row === null) {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['status'] !== 'published') {
            $this->app->db()->transaction(function () use ($row): void {
                $this->app->db()->update('videos', [
                    'status' => 'published',
                    'published_at' => $row['published_at'] ?? Str::now(),
                    'updated_at' => Str::now(),
                ], 'id = ?', [$row['id']]);
                $this->app->db()->run('UPDATE users SET video_count = video_count + 1 WHERE id = ?', [$row['user_id']]);
            });
        }

        return ['video_id' => $row['id'], 'status' => 'published'];
    }

    private function userPayload(string $id): array
    {
        $row = $this->app->users()->requireById($id);

        return [
            'id' => $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'status' => $row['status'],
            'verified' => (bool) $row['verified'],
            'created_at' => $row['created_at'],
        ];
    }
}
