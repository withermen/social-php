<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Support\Str;
use Social\Support\Validator;

final class ReportService
{
    private const REASONS = ['spam', 'harassment', 'hate', 'nudity', 'violence', 'self_harm', 'misinformation', 'copyright', 'impersonation', 'other'];

    public function __construct(private readonly App $app)
    {
    }

    public function create(array $user, array $data): array
    {
        $clean = Validator::validate($data, [
            'target_type' => 'required|in:user,video,comment',
            'target_id' => 'required|string:1,64',
            'reason' => 'required|in:' . implode(',', self::REASONS),
            'details' => 'nullable|string:0,1000',
        ]);
        $targetType = $clean['target_type'];
        $targetId = $clean['target_id'];

        if ($targetType === 'user' && $targetId === $user['id']) {
            throw ApiException::badRequest('You cannot report yourself.');
        }

        $this->ensureTarget($targetType, $targetId);
        $existing = $this->app->db()->fetch(
            'SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = ? LIMIT 1',
            [$user['id'], $targetType, $targetId, 'open']
        );

        if ($existing !== null) {
            throw ApiException::conflict('You already have an open report for this content.');
        }

        $id = Str::id();
        $this->app->db()->insert('reports', [
            'id' => $id,
            'reporter_id' => $user['id'],
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $clean['reason'],
            'details' => trim((string) ($clean['details'] ?? '')) ?: null,
            'status' => 'open',
            'created_at' => Str::now(),
        ]);

        return $this->payload($this->find($id));
    }

    public function list(string $status, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $where = '1 = 1';
        $params = [];

        if (in_array($status, ['open', 'resolved', 'dismissed'], true)) {
            $where = 'status = ?';
            $params[] = $status;
        }

        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM reports WHERE ' . $where, $params);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM reports WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items' => array_map(fn (array $row): array => $this->payload($row), $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    public function resolve(array $admin, string $id, string $status, ?string $note): array
    {
        $report = $this->find($id);

        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            throw new ValidationException(['status' => 'Status must be resolved or dismissed.']);
        }

        if ($note !== null && mb_strlen($note, 'UTF-8') > 1000) {
            throw new ValidationException(['note' => 'Note may not be longer than 1000 characters.']);
        }

        $this->app->db()->update('reports', [
            'status' => $status,
            'resolved_by' => $admin['id'],
            'resolved_at' => Str::now(),
            'resolution_note' => $note,
        ], 'id = ?', [$report['id']]);

        return $this->payload($this->find($id));
    }

    public function find(string $id): array
    {
        $report = $this->app->db()->fetch('SELECT * FROM reports WHERE id = ? LIMIT 1', [$id]);

        if ($report === null) {
            throw ApiException::notFound('Report not found.');
        }

        return $report;
    }

    private function ensureTarget(string $type, string $id): void
    {
        if ($type === 'video') {
            $this->app->videos()->findAccessible($id, null);
        } elseif ($type === 'comment') {
            $this->app->comments()->find($id);
        } else {
            $this->app->users()->requireById($id);
        }
    }

    private function payload(array $row): array
    {
        $reporter = $this->app->db()->fetch('SELECT id, username, display_name, avatar_path FROM users WHERE id = ?', [$row['reporter_id']]);
        $target = null;

        if ($row['target_type'] === 'video') {
            $video = $this->app->db()->fetch('SELECT id, title, status, visibility FROM videos WHERE id = ?', [$row['target_id']]);
            $target = $video === null ? null : [
                'type' => 'video',
                'id' => $video['id'],
                'label' => $video['title'],
                'status' => $video['status'],
                'visibility' => $video['visibility'],
            ];
        } elseif ($row['target_type'] === 'comment') {
            $comment = $this->app->db()->fetch('SELECT id, video_id, body, status FROM comments WHERE id = ?', [$row['target_id']]);
            $target = $comment === null ? null : [
                'type' => 'comment',
                'id' => $comment['id'],
                'label' => Str::excerpt($comment['body'], 100),
                'status' => $comment['status'],
                'video_id' => $comment['video_id'],
            ];
        } else {
            $user = $this->app->db()->fetch('SELECT id, username, display_name, status FROM users WHERE id = ?', [$row['target_id']]);
            $target = $user === null ? null : [
                'type' => 'user',
                'id' => $user['id'],
                'label' => '@' . $user['username'],
                'status' => $user['status'],
            ];
        }

        return [
            'id' => $row['id'],
            'reason' => $row['reason'],
            'details' => $row['details'],
            'status' => $row['status'],
            'reporter' => $reporter === null ? null : [
                'id' => $reporter['id'],
                'username' => $reporter['username'],
                'display_name' => $reporter['display_name'],
                'avatar_url' => $reporter['avatar_path'] ? $this->app->assetUrl($reporter['avatar_path']) : null,
            ],
            'target' => $target,
            'resolution_note' => $row['resolution_note'],
            'resolved_at' => $row['resolved_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
