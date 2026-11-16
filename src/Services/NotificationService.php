<?php

namespace Social\Services;

use Social\App;
use Social\Support\Str;

final class NotificationService
{
    public function __construct(private readonly App $app)
    {
    }

    public function create(?string $userId, ?string $actorId, string $type, ?string $targetType = null, ?string $targetId = null, array $data = []): void
    {
        if ($userId === null || $userId === $actorId) {
            return;
        }

        if (in_array($type, ['follow', 'like', 'save', 'mention'], true)) {
            $existing = $this->app->db()->fetch(
                'SELECT id FROM notifications WHERE user_id = ? AND actor_id = ? AND type = ? AND target_type = ? AND target_id = ? AND created_at > ? LIMIT 1',
                [$userId, $actorId, $type, $targetType, $targetId, Str::now(-3600)]
            );

            if ($existing !== null) {
                return;
            }
        }

        $this->app->db()->insert('notifications', [
            'id' => Str::id(),
            'user_id' => $userId,
            'actor_id' => $actorId,
            'type' => $type,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Str::now(),
        ]);
    }

    public function list(array $user, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$user['id']]);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$user['id']]
        );
        $unread = (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$user['id']]);
        $items = array_map(fn (array $row): array => $this->payload($row), $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'unread_count' => $unread,
        ];
    }

    public function unreadCount(array $user): int
    {
        return (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$user['id']]);
    }

    public function markRead(array $user, string $id): void
    {
        $this->app->db()->update(
            'notifications',
            ['read_at' => Str::now()],
            'id = ? AND user_id = ? AND read_at IS NULL',
            [$id, $user['id']]
        );
    }

    public function markAllRead(array $user): void
    {
        $this->app->db()->update('notifications', ['read_at' => Str::now()], 'user_id = ? AND read_at IS NULL', [$user['id']]);
    }

    private function payload(array $row): array
    {
        $actor = null;

        if ($row['actor_id'] !== null) {
            $actorRow = $this->app->db()->fetch('SELECT id, username, display_name, avatar_path, verified FROM users WHERE id = ? LIMIT 1', [$row['actor_id']]);
            $actor = $actorRow === null ? null : [
                'id' => $actorRow['id'],
                'username' => $actorRow['username'],
                'display_name' => $actorRow['display_name'],
                'avatar_url' => $actorRow['avatar_path'] ? $this->app->assetUrl($actorRow['avatar_path']) : null,
                'verified' => (bool) $actorRow['verified'],
            ];
        }

        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'actor' => $actor,
            'target_type' => $row['target_type'],
            'target_id' => $row['target_id'],
            'data' => $row['data'] === null ? null : json_decode($row['data'], true),
            'read' => $row['read_at'] !== null,
            'created_at' => $row['created_at'],
        ];
    }
}
