<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Http\UploadedFile;
use Social\Support\Str;
use Social\Support\Validator;

final class VideoService
{
    public function __construct(private readonly App $app)
    {
    }

    public function publish(array $user, string $sourcePath, string $originalName, string $clientMime, int $size, array $data, ?UploadedFile $thumbnail = null): array
    {
        $tagsInput = $data['tags'] ?? [];
        $tagsInput = is_string($tagsInput) ? explode(',', $tagsInput) : $tagsInput;

        if (!is_array($tagsInput)) {
            throw new ValidationException(['tags' => 'Tags must be an array or a comma separated string.']);
        }

        $clean = Validator::validate($data, [
            'title' => 'required|string:1,150',
            'description' => 'nullable|string:0,5000',
            'visibility' => 'nullable|in:public,unlisted,private',
        ]);
        $config = $this->app->config();
        $maxSize = (int) $config->get('uploads.max_size', 536870912);

        if ($size <= 0 || !is_file($sourcePath)) {
            throw new ValidationException(['video' => 'Video file is missing or empty.']);
        }

        if ($size > $maxSize) {
            throw ApiException::payloadTooLarge('Video exceeds the maximum upload size.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $detected = mime_content_type($sourcePath) ?: $clientMime;

        if ($detected === 'application/octet-stream' && str_starts_with($clientMime, 'video/')) {
            $detected = $clientMime;
        }

        if (!$this->app->media()->isVideo($detected, $extension, $config->get('uploads.allowed_video'), $config->get('uploads.allowed_video_mimes'))) {
            throw ApiException::unsupportedMedia('Unsupported video format.');
        }

        $probe = $this->app->media()->probe($sourcePath) ?? ['duration' => 0, 'width' => 0, 'height' => 0];
        $maxDuration = (int) $config->get('uploads.max_duration', 600);

        if ($maxDuration > 0 && $probe['duration'] > $maxDuration) {
            throw ApiException::badRequest('Video is longer than the allowed duration.');
        }

        $id = Str::id();
        $now = Str::now();
        $videoPath = 'videos/' . gmdate('Y/m') . '/' . $id . '.' . $extension;
        $thumbnailPath = 'thumbnails/' . $id . '.jpg';
        $absoluteThumb = $this->app->storage()->path($thumbnailPath);
        $thumbReady = false;
        $thumbMime = null;

        if ($thumbnail !== null) {
            if (!$thumbnail->isValid() || $thumbnail->size > 8 * 1024 * 1024) {
                throw new ValidationException(['thumbnail' => 'Thumbnail upload is invalid or too large.']);
            }

            $thumbMime = $thumbnail->detectedMime();

            if (!$this->app->media()->isImage($thumbMime, $thumbnail->extension(), $config->get('uploads.allowed_image'), $config->get('uploads.allowed_image_mimes'))) {
                throw ApiException::unsupportedMedia('Thumbnail must be a JPEG, PNG, WebP or GIF image.');
            }
        }

        $this->app->storage()->putFile($videoPath, $sourcePath);

        if ($thumbnail !== null) {
            $thumbReady = $this->app->media()->imageCover($thumbnail->path, $absoluteThumb, (int) $config->get('media.thumbnail_width', 720));
        }

        if (!$thumbReady) {
            $this->app->media()->thumbnail($this->app->storage()->path($videoPath), $absoluteThumb, $probe['duration']);
        }

        $description = trim((string) ($clean['description'] ?? '')) ?: null;
        $visibility = $clean['visibility'] ?? 'public';

        try {
            $this->app->db()->transaction(function () use ($id, $user, $clean, $description, $visibility, $videoPath, $thumbnailPath, $detected, $size, $probe, $now, $tagsInput): void {
                $this->app->db()->insert('videos', [
                    'id' => $id,
                    'user_id' => $user['id'],
                    'title' => $clean['title'],
                    'description' => $description,
                    'visibility' => $visibility,
                    'status' => 'published',
                    'storage_path' => $videoPath,
                    'thumbnail_path' => $thumbnailPath,
                    'mime' => $detected,
                    'size' => $size,
                    'duration' => $probe['duration'],
                    'width' => $probe['width'],
                    'height' => $probe['height'],
                    'like_count' => 0,
                    'comment_count' => 0,
                    'view_count' => 0,
                    'share_count' => 0,
                    'save_count' => 0,
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->syncHashtags($id, $clean['title'], $description, $tagsInput);
                $this->syncMentions($id, $user, $clean['title'], $description);
                $this->app->db()->run('UPDATE users SET video_count = video_count + 1, updated_at = ? WHERE id = ?', [$now, $user['id']]);
            });
        } catch (\Throwable $exception) {
            $this->app->storage()->delete($videoPath);
            $this->app->storage()->delete($thumbnailPath);
            throw $exception;
        }

        $row = $this->findRow($id);

        return $this->payloads([$row], $user['id'])[0];
    }

    public function get(string $id, ?string $viewerId): array
    {
        $row = $this->findAccessible($id, $viewerId);
        $rows = $this->payloads([$row], $viewerId);

        return $rows[0];
    }

    public function findAccessible(string $id, ?string $viewerId): array
    {
        $row = $this->findRow($id);

        if ($row === null) {
            throw ApiException::notFound('Video not found.');
        }

        $isOwner = $viewerId !== null && $row['user_id'] === $viewerId;
        $isAdmin = $viewerId !== null && in_array($this->roleOf($viewerId), ['admin', 'moderator'], true);

        if ($row['status'] !== 'published') {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['author_status'] !== 'active' && !$isOwner && !$isAdmin) {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['visibility'] === 'private' && !$isOwner && !$isAdmin) {
            throw ApiException::notFound('Video not found.');
        }

        if ($viewerId !== null && $viewerId !== $row['user_id'] && $this->app->users()->isBlockedBetween($viewerId, $row['user_id'])) {
            throw ApiException::notFound('Video not found.');
        }

        return $row;
    }

    public function update(array $user, string $id, array $data): array
    {
        $row = $this->findRow($id);

        if ($row === null) {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['user_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'moderator'], true)) {
            throw ApiException::forbidden('You cannot edit this video.');
        }

        $tagsInput = $data['tags'] ?? null;
        $tagsInput = is_string($tagsInput) ? explode(',', $tagsInput) : $tagsInput;
        $clean = Validator::validate($data, [
            'title' => 'nullable|string:1,150',
            'description' => 'nullable|string:0,5000',
            'visibility' => 'nullable|in:public,unlisted,private',
        ]);
        $update = [];

        foreach (['title', 'description', 'visibility'] as $field) {
            if (array_key_exists($field, $clean)) {
                $update[$field] = $clean[$field] === '' ? null : $clean[$field];
            }
        }

        if (array_key_exists('title', $update) && $update['title'] === null) {
            throw new ValidationException(['title' => 'Title cannot be empty.']);
        }

        if ($tagsInput !== null && !is_array($tagsInput)) {
            throw new ValidationException(['tags' => 'Tags must be an array or a comma separated string.']);
        }

        $update['updated_at'] = Str::now();
        $this->app->db()->transaction(function () use ($id, $update, $tagsInput, $row): void {
            $this->app->db()->update('videos', $update, 'id = ?', [$id]);
            $title = $update['title'] ?? $row['title'];
            $description = array_key_exists('description', $update) ? $update['description'] : $row['description'];

            if ($tagsInput !== null || array_key_exists('title', $update) || array_key_exists('description', $update)) {
                $this->syncHashtags($id, $title, $description, $tagsInput ?? []);
                $this->syncMentions($id, ['id' => $row['user_id'], 'username' => '', 'display_name' => ''], $title, $description);
            }
        });

        $fresh = $this->findRow($id);

        return $this->payloads([$fresh], $user['id'])[0];
    }

    public function delete(array $user, string $id): void
    {
        $row = $this->findRow($id);

        if ($row === null) {
            throw ApiException::notFound('Video not found.');
        }

        if ($row['user_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'moderator'], true)) {
            throw ApiException::forbidden('You cannot delete this video.');
        }

        if ($row['status'] === 'removed') {
            return;
        }

        $this->app->db()->transaction(function () use ($row): void {
            $this->app->db()->update('videos', ['status' => 'removed', 'updated_at' => Str::now()], 'id = ?', [$row['id']]);
            $this->app->db()->run(
                'UPDATE users SET video_count = CASE WHEN video_count > 0 THEN video_count - 1 ELSE 0 END WHERE id = ?',
                [$row['user_id']]
            );
        });
    }

    public function listByUser(string $username, ?string $viewerId, int $page, int $perPage): array
    {
        $author = $this->app->users()->byUsername($username);
        $isSelf = $viewerId !== null && $viewerId === $author['id'];
        $offset = ($page - 1) * $perPage;
        $where = 'v.user_id = ? AND v.status = ?';
        $params = [$author['id'], 'published'];

        if (!$isSelf) {
            $where .= ' AND v.visibility = ?';
            $params[] = 'public';
        }

        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM videos v WHERE ' . $where, $params);
        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified FROM videos v JOIN users u ON u.id = v.user_id WHERE ' . $where . ' ORDER BY v.published_at DESC, v.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items' => $this->payloads($rows, $viewerId),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function savedVideos(array $user, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM saves s JOIN videos v ON v.id = s.video_id WHERE s.user_id = ? AND v.status = ? AND v.visibility = ?',
            [$user['id'], 'published', 'public']
        );
        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified FROM saves s JOIN videos v ON v.id = s.video_id JOIN users u ON u.id = v.user_id WHERE s.user_id = ? AND v.status = ? AND v.visibility = ? ORDER BY s.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$user['id'], 'published', 'public']
        );

        return [
            'items' => $this->payloads($rows, $user['id']),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function like(array $user, string $id): array
    {
        $row = $this->findAccessible($id, $user['id']);
        $existing = $this->app->db()->fetch(
            'SELECT id FROM likes WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$user['id'], 'video', $id]
        );

        if ($existing === null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->insert('likes', [
                    'id' => Str::id(),
                    'user_id' => $user['id'],
                    'target_type' => 'video',
                    'target_id' => $id,
                    'created_at' => Str::now(),
                ]);
                $this->app->db()->run('UPDATE videos SET like_count = like_count + 1 WHERE id = ?', [$id]);
            });
            $this->app->notifications()->create($row['user_id'], $user['id'], 'like', 'video', $id, [
                'title' => $row['title'],
                'thumbnail_url' => $row['thumbnail_path'] ? $this->app->assetUrl($row['thumbnail_path']) : null,
            ]);
        }

        $count = (int) $this->app->db()->value('SELECT like_count FROM videos WHERE id = ?', [$id]);

        return ['liked' => true, 'like_count' => $count];
    }

    public function unlike(array $user, string $id): array
    {
        $row = $this->findAccessible($id, $user['id']);
        $existing = $this->app->db()->fetch(
            'SELECT id FROM likes WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$user['id'], 'video', $id]
        );

        if ($existing !== null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->delete('likes', 'id = ?', [$existing['id']]);
                $this->app->db()->run(
                    'UPDATE videos SET like_count = CASE WHEN like_count > 0 THEN like_count - 1 ELSE 0 END WHERE id = ?',
                    [$id]
                );
            });
        }

        $count = (int) $this->app->db()->value('SELECT like_count FROM videos WHERE id = ?', [$id]);

        return ['liked' => false, 'like_count' => $count];
    }

    public function save(array $user, string $id): array
    {
        $this->findAccessible($id, $user['id']);
        $existing = $this->app->db()->fetch('SELECT 1 FROM saves WHERE user_id = ? AND video_id = ?', [$user['id'], $id]);

        if ($existing === null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->insert('saves', [
                    'user_id' => $user['id'],
                    'video_id' => $id,
                    'created_at' => Str::now(),
                ]);
                $this->app->db()->run('UPDATE videos SET save_count = save_count + 1 WHERE id = ?', [$id]);
            });
        }

        $count = (int) $this->app->db()->value('SELECT save_count FROM videos WHERE id = ?', [$id]);

        return ['saved' => true, 'save_count' => $count];
    }

    public function unsave(array $user, string $id): array
    {
        $existing = $this->app->db()->fetch('SELECT 1 FROM saves WHERE user_id = ? AND video_id = ?', [$user['id'], $id]);

        if ($existing !== null) {
            $this->app->db()->transaction(function () use ($user, $id): void {
                $this->app->db()->delete('saves', 'user_id = ? AND video_id = ?', [$user['id'], $id]);
                $this->app->db()->run(
                    'UPDATE videos SET save_count = CASE WHEN save_count > 0 THEN save_count - 1 ELSE 0 END WHERE id = ?',
                    [$id]
                );
            });
        }

        $count = (int) $this->app->db()->value('SELECT save_count FROM videos WHERE id = ?', [$id]);

        return ['saved' => false, 'save_count' => $count];
    }

    public function share(?array $user, string $id, ?string $platform): array
    {
        $row = $this->findAccessible($id, $user['id'] ?? null);

        if ($platform !== null) {
            $platform = mb_substr(trim($platform), 0, 30, 'UTF-8');
        }

        $this->app->db()->transaction(function () use ($user, $id, $platform, $row): void {
            $this->app->db()->insert('shares', [
                'id' => Str::id(),
                'video_id' => $id,
                'user_id' => $user['id'] ?? null,
                'platform' => $platform ?: null,
                'created_at' => Str::now(),
            ]);
            $this->app->db()->run('UPDATE videos SET share_count = share_count + 1 WHERE id = ?', [$id]);
        });
        $count = (int) $this->app->db()->value('SELECT share_count FROM videos WHERE id = ?', [$id]);

        return [
            'share_count' => $count,
            'share_url' => $this->app->url('/v/' . $row['id']),
        ];
    }

    public function recordView(string $id, ?array $user, string $ipHash): array
    {
        $row = $this->findAccessible($id, $user['id'] ?? null);
        $since = Str::now(-1800);
        $counted = false;

        if ($user !== null) {
            $seen = $this->app->db()->fetch(
                'SELECT 1 FROM video_views WHERE video_id = ? AND user_id = ? AND viewed_at > ? LIMIT 1',
                [$id, $user['id'], $since]
            );
        } else {
            $seen = $this->app->db()->fetch(
                'SELECT 1 FROM video_views WHERE video_id = ? AND user_id IS NULL AND ip_hash = ? AND viewed_at > ? LIMIT 1',
                [$id, $ipHash, $since]
            );
        }

        if ($seen === null) {
            $counted = true;
            $this->app->db()->transaction(function () use ($id, $user, $ipHash): void {
                $this->app->db()->insert('video_views', [
                    'id' => Str::id(),
                    'video_id' => $id,
                    'user_id' => $user['id'] ?? null,
                    'ip_hash' => $user === null ? $ipHash : null,
                    'viewed_at' => Str::now(),
                ]);
                $this->app->db()->run('UPDATE videos SET view_count = view_count + 1 WHERE id = ?', [$id]);
                $this->app->db()->run(
                    'UPDATE hashtags SET view_count = view_count + 1 WHERE id IN (SELECT hashtag_id FROM video_hashtags WHERE video_id = ?)',
                    [$id]
                );
            });
        }

        $count = (int) $this->app->db()->value('SELECT view_count FROM videos WHERE id = ?', [$id]);

        return ['view_count' => $count, 'counted' => $counted, 'video_id' => $row['id']];
    }

    public function streamRow(string $id, ?string $viewerId): array
    {
        return $this->findAccessible($id, $viewerId);
    }

    public function likers(string $id, ?string $viewerId, int $page, int $perPage): array
    {
        $this->findAccessible($id, $viewerId);
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value(
            "SELECT COUNT(*) FROM likes l JOIN users u ON u.id = l.user_id WHERE l.target_type = 'video' AND l.target_id = ? AND u.status = 'active'",
            [$id]
        );
        $rows = $this->app->db()->fetchAll(
            "SELECT u.* FROM likes l JOIN users u ON u.id = l.user_id WHERE l.target_type = 'video' AND l.target_id = ? AND u.status = 'active' ORDER BY l.created_at DESC LIMIT " . $perPage . ' OFFSET ' . $offset,
            [$id]
        );
        $items = array_map(fn (array $row): array => $this->app->users()->publicProfile($row, $viewerId), $rows);

        return [
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function payloads(array $rows, ?string $viewerId): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (array $row): string => $row['id'], $rows)));
        $tags = [];
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $tagRows = $this->app->db()->fetchAll(
            'SELECT vh.video_id, h.tag FROM video_hashtags vh JOIN hashtags h ON h.id = vh.hashtag_id WHERE vh.video_id IN (' . $placeholders . ') ORDER BY h.tag ASC',
            $ids
        );

        foreach ($tagRows as $tagRow) {
            $tags[$tagRow['video_id']][] = $tagRow['tag'];
        }

        $liked = [];
        $saved = [];

        if ($viewerId !== null) {
            $likeRows = $this->app->db()->fetchAll(
                "SELECT target_id FROM likes WHERE user_id = ? AND target_type = 'video' AND target_id IN (" . $placeholders . ')',
                array_merge([$viewerId], $ids)
            );
            $saveRows = $this->app->db()->fetchAll(
                'SELECT video_id FROM saves WHERE user_id = ? AND video_id IN (' . $placeholders . ')',
                array_merge([$viewerId], $ids)
            );
            $liked = array_fill_keys(array_column($likeRows, 'target_id'), true);
            $saved = array_fill_keys(array_column($saveRows, 'video_id'), true);
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->payload(
                $row,
                $viewerId,
                isset($liked[$row['id']]),
                isset($saved[$row['id']]),
                $tags[$row['id']] ?? []
            );
        }

        return $items;
    }

    private function payload(array $row, ?string $viewerId, bool $liked, bool $saved, array $tags): array
    {
        $author = isset($row['author_username'])
            ? [
                'id' => $row['user_id'],
                'username' => $row['author_username'],
                'display_name' => $row['author_display_name'],
                'avatar_url' => $row['author_avatar_path'] ? $this->app->assetUrl($row['author_avatar_path']) : null,
                'verified' => (bool) $row['author_verified'],
            ]
            : $this->authorPayload($row['user_id']);

        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'visibility' => $row['visibility'],
            'duration' => (int) $row['duration'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'size' => (int) $row['size'],
            'mime' => $row['mime'],
            'thumbnail_url' => $row['thumbnail_path'] ? $this->app->assetUrl($row['thumbnail_path']) : null,
            'stream_url' => $this->app->url('/api/v1/videos/' . $row['id'] . '/stream'),
            'share_url' => $this->app->url('/v/' . $row['id']),
            'tags' => $tags,
            'author' => $author,
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'view_count' => (int) $row['view_count'],
            'share_count' => (int) $row['share_count'],
            'save_count' => (int) $row['save_count'],
            'liked' => $liked,
            'saved' => $saved,
            'is_owner' => $viewerId !== null && $viewerId === $row['user_id'],
            'published_at' => $row['published_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function authorPayload(string $userId): ?array
    {
        $row = $this->app->db()->fetch('SELECT id, username, display_name, avatar_path, verified FROM users WHERE id = ?', [$userId]);

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row['id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'avatar_url' => $row['avatar_path'] ? $this->app->assetUrl($row['avatar_path']) : null,
            'verified' => (bool) $row['verified'],
        ];
    }

    private function findRow(string $id): ?array
    {
        return $this->app->db()->fetch(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified, u.status AS author_status FROM videos v JOIN users u ON u.id = v.user_id WHERE v.id = ? LIMIT 1',
            [$id]
        );
    }

    private function roleOf(string $userId): string
    {
        return (string) ($this->app->db()->value('SELECT role FROM users WHERE id = ?', [$userId]) ?? 'user');
    }

    private function syncHashtags(string $videoId, string $title, ?string $description, array $extraTags): void
    {
        $text = $title . ' ' . ($description ?? '');
        preg_match_all('/#([\p{L}\p{N}_]{1,50})/u', $text, $matches);
        $tags = $matches[1] ?? [];

        foreach ($extraTags as $tag) {
            if (is_scalar($tag)) {
                $tags[] = (string) $tag;
            }
        }

        $normalized = [];

        foreach ($tags as $tag) {
            $tag = Str::normalizeTag($tag);

            if ($tag !== '') {
                $normalized[$tag] = true;
            }
        }

        $normalized = array_slice(array_keys($normalized), 0, 20);
        $oldRows = $this->app->db()->fetchAll('SELECT hashtag_id FROM video_hashtags WHERE video_id = ?', [$videoId]);
        $oldIds = array_column($oldRows, 'hashtag_id');

        foreach ($oldIds as $oldId) {
            $this->app->db()->run(
                'UPDATE hashtags SET video_count = CASE WHEN video_count > 0 THEN video_count - 1 ELSE 0 END, updated_at = ? WHERE id = ?',
                [Str::now(), $oldId]
            );
        }

        $this->app->db()->delete('video_hashtags', 'video_id = ?', [$videoId]);

        foreach ($normalized as $tag) {
            $existing = $this->app->db()->fetch('SELECT * FROM hashtags WHERE tag = ? LIMIT 1', [$tag]);

            if ($existing === null) {
                $hashtagId = Str::id();
                $this->app->db()->insert('hashtags', [
                    'id' => $hashtagId,
                    'tag' => $tag,
                    'video_count' => 0,
                    'view_count' => 0,
                    'created_at' => Str::now(),
                    'updated_at' => Str::now(),
                ]);
            } else {
                $hashtagId = $existing['id'];
            }

            $this->app->db()->run('UPDATE hashtags SET video_count = video_count + 1, updated_at = ? WHERE id = ?', [Str::now(), $hashtagId]);
            $this->app->db()->insert('video_hashtags', [
                'video_id' => $videoId,
                'hashtag_id' => $hashtagId,
                'created_at' => Str::now(),
            ]);
        }
    }

    private function syncMentions(string $videoId, array $author, string $title, ?string $description): void
    {
        $this->app->db()->delete('mentions', 'target_type = ? AND target_id = ?', ['video', $videoId]);
        $text = $title . ' ' . ($description ?? '');
        preg_match_all('/@([a-zA-Z0-9_.]{3,24})/u', $text, $matches);
        $usernames = array_values(array_unique(array_map(fn (string $name): string => strtolower($name), $matches[1] ?? [])));

        foreach ($usernames as $username) {
            $mentioned = $this->app->db()->fetch('SELECT id, username, display_name FROM users WHERE username = ? AND status = ? LIMIT 1', [$username, 'active']);

            if ($mentioned === null) {
                continue;
            }

            $this->app->db()->insert('mentions', [
                'id' => Str::id(),
                'user_id' => $author['id'],
                'mentioned_user_id' => $mentioned['id'],
                'target_type' => 'video',
                'target_id' => $videoId,
                'created_at' => Str::now(),
            ]);
            $this->app->notifications()->create($mentioned['id'], $author['id'], 'mention', 'video', $videoId, ['title' => $title]);
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
