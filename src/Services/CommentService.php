<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Support\Str;
use Social\Support\Validator;

final class CommentService
{
    public function __construct(private readonly App $app)
    {
    }

    public function list(string $videoId, ?string $viewerId, int $page, int $perPage, ?string $parentId = null): array
    {
        $this->app->videos()->findAccessible($videoId, $viewerId);
        $offset = ($page - 1) * $perPage;

        if ($parentId !== null) {
            $parent = $this->find($parentId);

            if ($parent['video_id'] !== $videoId || $parent['parent_id'] !== null) {
                throw ApiException::notFound('Comment not found.');
            }

            $total = (int) $this->app->db()->value(
                'SELECT COUNT(*) FROM comments WHERE video_id = ? AND parent_id = ? AND (status = ? OR reply_count > 0)',
                [$videoId, $parentId, 'published']
            );
            $rows = $this->app->db()->fetchAll(
                'SELECT * FROM comments WHERE video_id = ? AND parent_id = ? AND (status = ? OR reply_count > 0) ORDER BY created_at ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
                [$videoId, $parentId, 'published']
            );

            return [
                'items' => $this->mapRows($rows, $viewerId, false),
                'pagination' => $this->pagination($page, $perPage, $total),
            ];
        }

        $where = "video_id = ? AND parent_id IS NULL AND (status = ? OR reply_count > 0)";
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM comments WHERE ' . $where, [$videoId, 'published']);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM comments WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$videoId, 'published']
        );

        return [
            'items' => $this->mapRows($rows, $viewerId, true),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function create(array $user, string $videoId, array $data): array
    {
        $limit = $this->app->rateLimiter()->attempt('comment:' . $user['id'], 30, 60);

        if (!$limit['allowed']) {
            throw ApiException::tooManyAttempts('You are commenting too fast.', $limit['retry_after']);
        }

        $clean = Validator::validate($data, ['body' => 'required|string:1,1000']);
        $video = $this->app->videos()->findAccessible($videoId, $user['id']);
        $parentId = $data['parent_id'] ?? null;
        $parent = null;
        $topId = null;
        $replyTo = null;

        if ($parentId !== null && $parentId !== '') {
            $parent = $this->find((string) $parentId);

            if ($parent['video_id'] !== $videoId) {
                throw ApiException::badRequest('Parent comment does not belong to this video.');
            }

            if ($parent['status'] !== 'published') {
                throw ApiException::badRequest('Parent comment is not available.');
            }

            $topId = $parent['parent_id'] ?? $parent['id'];
            $replyTo = $parent['id'];
        }

        $id = Str::id();
        $now = Str::now();
        $this->app->db()->transaction(function () use ($id, $videoId, $user, $clean, $topId, $replyTo, $now): void {
            $this->app->db()->insert('comments', [
                'id' => $id,
                'video_id' => $videoId,
                'user_id' => $user['id'],
                'parent_id' => $topId,
                'reply_to_id' => $replyTo,
                'body' => $clean['body'],
                'like_count' => 0,
                'reply_count' => 0,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->app->db()->run('UPDATE videos SET comment_count = comment_count + 1 WHERE id = ?', [$videoId]);

            if ($topId !== null) {
                $this->app->db()->run('UPDATE comments SET reply_count = reply_count + 1 WHERE id = ?', [$topId]);
            }
        });

        $this->app->notifications()->create($video['user_id'], $user['id'], 'comment', 'video', $videoId, [
            'comment_id' => $id,
            'preview' => Str::excerpt($clean['body'], 90),
        ]);

        if ($parent !== null) {
            $this->app->notifications()->create($parent['user_id'], $user['id'], 'reply', 'comment', $id, [
                'video_id' => $videoId,
                'preview' => Str::excerpt($clean['body'], 90),
            ]);
        }

        $this->notifyMentions($user, $videoId, $id, $clean['body']);
        $this->app->analysis()->recordSignal($user['id'], $videoId, 'comment');

        return $this->mapRows([$this->find($id)], $user['id'], false)[0];
    }

    public function update(array $user, string $id, array $data): array
    {
        $comment = $this->find($id);

        if ($comment['status'] !== 'published') {
            throw ApiException::notFound('Comment not found.');
        }

        if ($comment['user_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'moderator'], true)) {
            throw ApiException::forbidden('You cannot edit this comment.');
        }

        $clean = Validator::validate($data, ['body' => 'required|string:1,1000']);
        $this->app->db()->update('comments', ['body' => $clean['body'], 'updated_at' => Str::now()], 'id = ?', [$id]);
        $fresh = $this->find($id);

        return $this->mapRows([$fresh], $user['id'], false)[0];
    }

    public function delete(array $user, string $id): void
    {
        $comment = $this->find($id);

        if ($comment['status'] !== 'published') {
            return;
        }

        if ($comment['user_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'moderator'], true)) {
            throw ApiException::forbidden('You cannot delete this comment.');
        }

        $this->app->db()->transaction(function () use ($comment): void {
            $this->app->db()->update('comments', ['status' => 'deleted', 'updated_at' => Str::now()], 'id = ?', [$comment['id']]);
            $this->app->db()->run(
                'UPDATE videos SET comment_count = CASE WHEN comment_count > 0 THEN comment_count - 1 ELSE 0 END WHERE id = ?',
                [$comment['video_id']]
            );

            if ($comment['parent_id'] !== null) {
                $this->app->db()->run(
                    'UPDATE comments SET reply_count = CASE WHEN reply_count > 0 THEN reply_count - 1 ELSE 0 END WHERE id = ?',
                    [$comment['parent_id']]
                );
            }
        });
    }

    public function like(array $user, string $id): array
    {
        $comment = $this->published($id);
        $existing = $this->app->db()->fetch(
            'SELECT id FROM likes WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$user['id'], 'comment', $id]
        );

        if ($existing === null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->insert('likes', [
                    'id' => Str::id(),
                    'user_id' => $user['id'],
                    'target_type' => 'comment',
                    'target_id' => $id,
                    'created_at' => Str::now(),
                ]);
                $this->app->db()->run('UPDATE comments SET like_count = like_count + 1 WHERE id = ?', [$id]);
            });
            $this->app->notifications()->create($comment['user_id'], $user['id'], 'comment_like', 'comment', $id, [
                'preview' => Str::excerpt($comment['body'], 90),
            ]);
        }

        $count = (int) $this->app->db()->value('SELECT like_count FROM comments WHERE id = ?', [$id]);

        return ['liked' => true, 'like_count' => $count];
    }

    public function unlike(array $user, string $id): array
    {
        $this->published($id);
        $existing = $this->app->db()->fetch(
            'SELECT id FROM likes WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$user['id'], 'comment', $id]
        );

        if ($existing !== null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->delete('likes', 'id = ?', [$existing['id']]);
                $this->app->db()->run(
                    'UPDATE comments SET like_count = CASE WHEN like_count > 0 THEN like_count - 1 ELSE 0 END WHERE id = ?',
                    [$id]
                );
            });
        }

        $count = (int) $this->app->db()->value('SELECT like_count FROM comments WHERE id = ?', [$id]);

        return ['liked' => false, 'like_count' => $count];
    }

    public function find(string $id): array
    {
        $comment = $this->app->db()->fetch('SELECT * FROM comments WHERE id = ? LIMIT 1', [$id]);

        if ($comment === null) {
            throw ApiException::notFound('Comment not found.');
        }

        return $comment;
    }

    private function published(string $id): array
    {
        $comment = $this->find($id);

        if ($comment['status'] !== 'published') {
            throw ApiException::notFound('Comment not found.');
        }

        return $comment;
    }

    private function mapRows(array $rows, ?string $viewerId, bool $includeReplies): array
    {
        if ($rows === []) {
            return [];
        }

        $all = $rows;

        if ($includeReplies) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $replies = $this->app->db()->fetchAll(
                'SELECT * FROM comments WHERE parent_id IN (' . $placeholders . ') AND (status = ? OR reply_count > 0) ORDER BY created_at ASC',
                array_merge($ids, ['published'])
            );
            $all = array_merge($rows, $replies);
        }

        $authorIds = array_values(array_unique(array_column($all, 'user_id')));
        $authors = [];
        $placeholders = implode(', ', array_fill(0, count($authorIds), '?'));
        $authorRows = $this->app->db()->fetchAll(
            'SELECT id, username, display_name, avatar_path, verified FROM users WHERE id IN (' . $placeholders . ')',
            $authorIds
        );

        foreach ($authorRows as $authorRow) {
            $authors[$authorRow['id']] = [
                'id' => $authorRow['id'],
                'username' => $authorRow['username'],
                'display_name' => $authorRow['display_name'],
                'avatar_url' => $authorRow['avatar_path'] ? $this->app->assetUrl($authorRow['avatar_path']) : null,
                'verified' => (bool) $authorRow['verified'],
            ];
        }

        $liked = [];

        if ($viewerId !== null) {
            $ids = array_column($all, 'id');
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $likes = $this->app->db()->fetchAll(
                "SELECT target_id FROM likes WHERE user_id = ? AND target_type = 'comment' AND target_id IN (" . $placeholders . ')',
                array_merge([$viewerId], $ids)
            );
            $liked = array_fill_keys(array_column($likes, 'target_id'), true);
        }

        $replyMap = [];

        if ($includeReplies) {
            foreach ($all as $comment) {
                if ($comment['parent_id'] !== null) {
                    $replyMap[$comment['parent_id']][] = $comment;
                }
            }
        }

        $items = [];

        foreach ($rows as $row) {
            $item = $this->payload($row, $viewerId, $authors[$row['user_id']] ?? null, isset($liked[$row['id']]));
            $item['replies'] = [];

            if ($includeReplies && isset($replyMap[$row['id']])) {
                foreach (array_slice($replyMap[$row['id']], 0, 5) as $reply) {
                    $item['replies'][] = $this->payload($reply, $viewerId, $authors[$reply['user_id']] ?? null, isset($liked[$reply['id']]));
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    private function payload(array $row, ?string $viewerId, ?array $author, bool $liked): array
    {
        $deleted = $row['status'] !== 'published';

        return [
            'id' => $row['id'],
            'video_id' => $row['video_id'],
            'parent_id' => $row['parent_id'],
            'reply_to_id' => $row['reply_to_id'],
            'body' => $deleted ? null : $row['body'],
            'deleted' => $deleted,
            'author' => $author,
            'like_count' => (int) $row['like_count'],
            'reply_count' => (int) $row['reply_count'],
            'liked' => $liked,
            'is_owner' => $viewerId !== null && $viewerId === $row['user_id'],
            'replies' => [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function notifyMentions(array $user, string $videoId, string $commentId, string $body): void
    {
        preg_match_all('/@([a-zA-Z0-9_.]{3,24})/u', $body, $matches);
        $usernames = array_values(array_unique(array_map(fn (string $name): string => strtolower($name), $matches[1] ?? [])));

        foreach ($usernames as $username) {
            $mentioned = $this->app->db()->fetch('SELECT id FROM users WHERE username = ? AND status = ? LIMIT 1', [$username, 'active']);

            if ($mentioned === null) {
                continue;
            }

            $this->app->db()->insert('mentions', [
                'id' => Str::id(),
                'user_id' => $user['id'],
                'mentioned_user_id' => $mentioned['id'],
                'target_type' => 'comment',
                'target_id' => $commentId,
                'created_at' => Str::now(),
            ]);
            $this->app->notifications()->create($mentioned['id'], $user['id'], 'mention', 'comment', $commentId, [
                'video_id' => $videoId,
                'preview' => Str::excerpt($body, 90),
            ]);
        }
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
