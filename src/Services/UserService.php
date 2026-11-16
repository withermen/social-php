<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Http\UploadedFile;
use Social\Support\Str;
use Social\Support\Validator;

final class UserService
{
    public function __construct(private readonly App $app)
    {
    }

    public function profile(string $username, ?string $viewerId): array
    {
        $user = $this->byUsername($username);

        if ($viewerId !== null && $viewerId !== $user['id'] && $this->isBlockedBetween($viewerId, $user['id'])) {
            throw ApiException::notFound('User not found.');
        }

        return $this->publicProfile($user, $viewerId);
    }

    public function byUsername(string $username): array
    {
        $user = $this->app->db()->fetch(
            'SELECT * FROM users WHERE username = ? AND status = ? LIMIT 1',
            [Str::normalizeUsername($username), 'active']
        );

        if ($user === null) {
            throw ApiException::notFound('User not found.');
        }

        return $user;
    }

    public function requireById(string $id): array
    {
        $user = $this->app->db()->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);

        if ($user === null) {
            throw ApiException::notFound('User not found.');
        }

        return $user;
    }

    public function updateProfile(array $user, array $data): array
    {
        $clean = Validator::validate($data, [
            'display_name' => 'nullable|string:1,100',
            'bio' => 'nullable|string:0,500',
            'website' => 'nullable|string:0,255|url',
            'location' => 'nullable|string:0,100',
        ]);
        $update = [];

        foreach (['display_name', 'bio', 'website', 'location'] as $field) {
            if (array_key_exists($field, $clean)) {
                $update[$field] = $clean[$field] === '' ? null : $clean[$field];
            }
        }

        if (array_key_exists('display_name', $update) && $update['display_name'] === null) {
            throw new ValidationException(['display_name' => 'Display name cannot be empty.']);
        }

        if ($update !== []) {
            $update['updated_at'] = Str::now();
            $this->app->db()->update('users', $update, 'id = ?', [$user['id']]);
        }

        return $this->app->auth()->toSelf($this->requireById($user['id']));
    }

    public function uploadAvatar(array $user, UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new ValidationException(['avatar' => 'Upload failed or file is empty.']);
        }

        if ($file->size > 5 * 1024 * 1024) {
            throw ApiException::payloadTooLarge('Avatar must be smaller than 5 MB.');
        }

        $config = $this->app->config();
        $extension = $file->extension();
        $mime = $file->detectedMime();

        if (!$this->app->media()->isImage($mime, $extension, $config->get('uploads.allowed_image'), $config->get('uploads.allowed_image_mimes'))) {
            throw ApiException::unsupportedMedia('Avatar must be a JPEG, PNG, WebP or GIF image.');
        }

        $temporary = $this->app->storage()->path('tmp/avatar-' . $user['id'] . '-' . Str::token(4) . '.jpg');
        $size = (int) $config->get('media.avatar_size', 400);

        if (!$this->app->media()->avatar($file->path, $temporary, $size)) {
            throw ApiException::unsupportedMedia('Image could not be processed.');
        }

        $path = 'avatars/' . $user['id'] . '-' . time() . '.jpg';
        $this->app->storage()->putFile($path, $temporary);
        @unlink($temporary);

        if ($user['avatar_path']) {
            $this->app->storage()->delete($user['avatar_path']);
        }

        $this->app->db()->update('users', ['avatar_path' => $path, 'updated_at' => Str::now()], 'id = ?', [$user['id']]);

        return $this->app->auth()->toSelf($this->requireById($user['id']));
    }

    public function removeAvatar(array $user): array
    {
        if ($user['avatar_path']) {
            $this->app->storage()->delete($user['avatar_path']);
        }

        $this->app->db()->update('users', ['avatar_path' => null, 'updated_at' => Str::now()], 'id = ?', [$user['id']]);

        return $this->app->auth()->toSelf($this->requireById($user['id']));
    }

    public function follow(array $user, string $username): array
    {
        $target = $this->byUsername($username);

        if ($target['id'] === $user['id']) {
            throw ApiException::badRequest('You cannot follow yourself.');
        }

        if ($this->isBlockedBetween($user['id'], $target['id'])) {
            throw ApiException::forbidden('This profile is not available.');
        }

        $existing = $this->app->db()->fetch(
            'SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?',
            [$user['id'], $target['id']]
        );

        if ($existing === null) {
            $now = Str::now();
            $this->app->db()->transaction(function () use ($user, $target, $now): void {
                $this->app->db()->insert('follows', [
                    'follower_id' => $user['id'],
                    'following_id' => $target['id'],
                    'created_at' => $now,
                ]);
                $this->app->db()->run('UPDATE users SET following_count = following_count + 1 WHERE id = ?', [$user['id']]);
                $this->app->db()->run('UPDATE users SET follower_count = follower_count + 1 WHERE id = ?', [$target['id']]);
            });
            $this->app->notifications()->create($target['id'], $user['id'], 'follow', 'user', $user['id']);
        }

        $target = $this->requireById($target['id']);

        return $this->publicProfile($target, $user['id'], ['is_following' => true]);
    }

    public function unfollow(array $user, string $username): array
    {
        $target = $this->byUsername($username);
        $existing = $this->app->db()->fetch(
            'SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?',
            [$user['id'], $target['id']]
        );

        if ($existing !== null) {
            $this->app->db()->transaction(function () use ($user, $target): void {
                $this->app->db()->delete('follows', 'follower_id = ? AND following_id = ?', [$user['id'], $target['id']]);
                $this->decrement('following_count', $user['id']);
                $this->decrement('follower_count', $target['id']);
            });
        }

        $target = $this->requireById($target['id']);

        return $this->publicProfile($target, $user['id'], ['is_following' => false]);
    }

    public function block(array $user, string $username): void
    {
        $target = $this->byUsername($username);

        if ($target['id'] === $user['id']) {
            throw ApiException::badRequest('You cannot block yourself.');
        }

        if ($this->app->db()->fetch('SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$user['id'], $target['id']]) !== null) {
            return;
        }

        $this->app->db()->transaction(function () use ($user, $target): void {
            $this->app->db()->insert('blocks', [
                'blocker_id' => $user['id'],
                'blocked_id' => $target['id'],
                'created_at' => Str::now(),
            ]);
            $this->removeFollowBetween($user['id'], $target['id']);
        });
    }

    public function unblock(array $user, string $username): void
    {
        $target = $this->byUsername($username);
        $this->app->db()->delete('blocks', 'blocker_id = ? AND blocked_id = ?', [$user['id'], $target['id']]);
    }

    public function mute(array $user, string $username): void
    {
        $target = $this->byUsername($username);

        if ($target['id'] === $user['id']) {
            throw ApiException::badRequest('You cannot mute yourself.');
        }

        if ($this->app->db()->fetch('SELECT 1 FROM mutes WHERE muter_id = ? AND muted_id = ?', [$user['id'], $target['id']]) === null) {
            $this->app->db()->insert('mutes', [
                'muter_id' => $user['id'],
                'muted_id' => $target['id'],
                'created_at' => Str::now(),
            ]);
        }
    }

    public function unmute(array $user, string $username): void
    {
        $target = $this->byUsername($username);
        $this->app->db()->delete('mutes', 'muter_id = ? AND muted_id = ?', [$user['id'], $target['id']]);
    }

    public function followers(string $username, ?string $viewerId, int $page, int $perPage): array
    {
        $target = $this->byUsername($username);
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM follows WHERE following_id = ?', [$target['id']]);
        $rows = $this->app->db()->fetchAll(
            'SELECT u.* FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.following_id = ? AND u.status = ? ORDER BY f.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$target['id'], 'active']
        );

        return [
            'items' => $this->mapUsers($rows, $viewerId),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function following(string $username, ?string $viewerId, int $page, int $perPage): array
    {
        $target = $this->byUsername($username);
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM follows WHERE follower_id = ?', [$target['id']]);
        $rows = $this->app->db()->fetchAll(
            'SELECT u.* FROM follows f JOIN users u ON u.id = f.following_id WHERE f.follower_id = ? AND u.status = ? ORDER BY f.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$target['id'], 'active']
        );

        return [
            'items' => $this->mapUsers($rows, $viewerId),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function deleteAccount(array $user): void
    {
        if ($user['avatar_path']) {
            $this->app->storage()->delete($user['avatar_path']);
        }

        $this->app->db()->update('users', [
            'status' => 'deleted',
            'bio' => null,
            'website' => null,
            'location' => null,
            'avatar_path' => null,
            'updated_at' => Str::now(),
        ], 'id = ?', [$user['id']]);
        $this->app->auth()->revokeRefreshTokens($user['id']);
    }

    public function publicProfile(array $user, ?string $viewerId, ?array $flags = null): array
    {
        $flags ??= $this->relationFlags($user['id'], $viewerId);

        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'],
            'bio' => $user['bio'],
            'website' => $user['website'],
            'location' => $user['location'],
            'avatar_url' => $user['avatar_path'] ? $this->app->assetUrl($user['avatar_path']) : null,
            'verified' => (bool) $user['verified'],
            'follower_count' => (int) $user['follower_count'],
            'following_count' => (int) $user['following_count'],
            'video_count' => (int) $user['video_count'],
            'created_at' => $user['created_at'],
            'is_me' => $viewerId === $user['id'],
            'is_following' => (bool) ($flags['is_following'] ?? false),
            'is_blocked' => (bool) ($flags['is_blocked'] ?? false),
            'is_muted' => (bool) ($flags['is_muted'] ?? false),
        ];
    }

    public function summary(array $user): array
    {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'],
            'avatar_url' => $user['avatar_path'] ? $this->app->assetUrl($user['avatar_path']) : null,
            'verified' => (bool) $user['verified'],
        ];
    }

    public function isBlockedBetween(string $first, string $second): bool
    {
        return $this->app->db()->fetch(
            'SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1',
            [$first, $second, $second, $first]
        ) !== null;
    }

    public function relationFlags(string $targetId, ?string $viewerId): array
    {
        if ($viewerId === null) {
            return ['is_following' => false, 'is_blocked' => false, 'is_muted' => false];
        }

        return [
            'is_following' => $this->app->db()->fetch(
                'SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?',
                [$viewerId, $targetId]
            ) !== null,
            'is_blocked' => $this->app->db()->fetch(
                'SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?',
                [$viewerId, $targetId]
            ) !== null,
            'is_muted' => $this->app->db()->fetch(
                'SELECT 1 FROM mutes WHERE muter_id = ? AND muted_id = ?',
                [$viewerId, $targetId]
            ) !== null,
        ];
    }

    private function mapUsers(array $rows, ?string $viewerId): array
    {
        return array_map(function (array $row) use ($viewerId): array {
            return $this->publicProfile($row, $viewerId);
        }, $rows);
    }

    private function removeFollowBetween(string $first, string $second): void
    {
        $forward = $this->app->db()->fetch('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?', [$first, $second]);
        $backward = $this->app->db()->fetch('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?', [$second, $first]);

        if ($forward !== null) {
            $this->app->db()->delete('follows', 'follower_id = ? AND following_id = ?', [$first, $second]);
            $this->decrement('following_count', $first);
            $this->decrement('follower_count', $second);
        }

        if ($backward !== null) {
            $this->app->db()->delete('follows', 'follower_id = ? AND following_id = ?', [$second, $first]);
            $this->decrement('following_count', $second);
            $this->decrement('follower_count', $first);
        }
    }

    private function decrement(string $column, string $userId): void
    {
        $allowed = ['follower_count', 'following_count', 'video_count'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        $this->app->db()->run(
            'UPDATE users SET ' . $column . ' = CASE WHEN ' . $column . ' > 0 THEN ' . $column . ' - 1 ELSE 0 END WHERE id = ?',
            [$userId]
        );
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
