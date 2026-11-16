<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ValidationException;
use Social\Support\Validator;

final class SearchService
{
    public function __construct(private readonly App $app)
    {
    }

    public function search(string $query, string $type, ?array $viewer, int $page, int $perPage): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new ValidationException(['q' => 'Search query is required.']);
        }

        $clean = Validator::validate(['q' => $query], ['q' => 'required|string:1,100']);
        $query = $clean['q'];
        $type = in_array($type, ['all', 'users', 'videos', 'hashtags'], true) ? $type : 'all';
        $limit = $type === 'all' ? 10 : $perPage;
        $offset = $type === 'all' ? 0 : ($page - 1) * $perPage;
        $result = ['query' => $query, 'type' => $type];

        if ($type === 'all' || $type === 'users') {
            $result['users'] = $this->users($query, $viewer, $limit, $offset);
        }

        if ($type === 'all' || $type === 'videos') {
            $result['videos'] = $this->videos($query, $viewer, $limit, $offset);
        }

        if ($type === 'all' || $type === 'hashtags') {
            $result['hashtags'] = $this->hashtags($query, $limit, $offset);
        }

        if ($type !== 'all') {
            $result['pagination'] = [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => count($result[$type] ?? []) === $perPage,
            ];
        }

        return $result;
    }

    private function users(string $query, ?array $viewer, int $limit, int $offset): array
    {
        $term = $this->likeTerm($query);
        $where = "status = 'active' AND (username LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' OR bio LIKE ? ESCAPE '!')";
        $params = [$term, $term, $term];

        if ($viewer !== null) {
            $where .= ' AND id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
                . ' AND id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)';
            $params[] = $viewer['id'];
            $params[] = $viewer['id'];
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM users WHERE ' . $where . ' ORDER BY follower_count DESC, username ASC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return array_map(fn (array $row): array => $this->app->users()->publicProfile($row, $viewer['id'] ?? null), $rows);
    }

    private function videos(string $query, ?array $viewer, int $limit, int $offset): array
    {
        $term = $this->likeTerm($query);
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active' AND (v.title LIKE ? ESCAPE '!' OR v.description LIKE ? ESCAPE '!')";
        $params = [$term, $term];

        if ($viewer !== null) {
            $where .= ' AND v.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
                . ' AND v.user_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)'
                . ' AND v.user_id NOT IN (SELECT muted_id FROM mutes WHERE muter_id = ?)';
            $params[] = $viewer['id'];
            $params[] = $viewer['id'];
            $params[] = $viewer['id'];
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified
             FROM videos v JOIN users u ON u.id = v.user_id
             WHERE ' . $where . '
             ORDER BY (v.like_count + v.comment_count * 2 + v.view_count / 10) DESC, v.published_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return $this->app->videos()->payloads($rows, $viewer['id'] ?? null);
    }

    private function hashtags(string $query, int $limit, int $offset): array
    {
        $term = $this->likeTerm($query);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM hashtags WHERE tag LIKE ? ESCAPE \'!\' ORDER BY video_count DESC, view_count DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$term]
        );

        return array_map(fn (array $row): array => [
            'tag' => $row['tag'],
            'video_count' => (int) $row['video_count'],
            'view_count' => (int) $row['view_count'],
        ], $rows);
    }

    private function likeTerm(string $query): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    }
}
