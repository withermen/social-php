<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;

final class FeedService
{
    public function __construct(private readonly App $app)
    {
    }

    public function following(array $user, int $page, int $perPage, ?string $format = null): array
    {
        $offset = ($page - 1) * $perPage;
        $format = $this->app->platform()->formatFilter($format);
        [$durationClause, $durationParams] = $this->app->platform()->durationClause($format);
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active'"
            . ' AND (v.user_id = ? OR v.user_id IN (SELECT following_id FROM follows WHERE follower_id = ?))'
            . ' AND v.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
            . ' AND v.user_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)'
            . ' AND v.user_id NOT IN (SELECT muted_id FROM mutes WHERE muter_id = ?)'
            . ' AND ' . $durationClause;
        $params = array_merge([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']], $durationParams);
        $total = (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM videos v JOIN users u ON u.id = v.user_id WHERE ' . $where,
            $params
        );
        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified FROM videos v JOIN users u ON u.id = v.user_id WHERE ' . $where . ' ORDER BY v.published_at DESC, v.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items' => $this->app->videos()->payloads($rows, $user['id']),
            'pagination' => $this->pagination($page, $perPage, $total),
            'algorithm' => 'following',
            'format' => $format,
        ];
    }

    public function forYou(?array $user, int $page, int $perPage, ?string $format = null): array
    {
        return $this->ranked($user, $page, $perPage, true, (int) $this->app->config()->get('feed.window_days', 14), 'foryou', $format);
    }

    public function explore(?array $user, int $page, int $perPage, ?string $format = null): array
    {
        return $this->ranked($user, $page, $perPage, false, 7, 'explore', $format);
    }

    public function trends(int $days, int $limit): array
    {
        $days = max(1, min(90, $days));
        $limit = max(1, min(50, $limit));
        $since = Str::now(-$days * 86400);
        $rows = $this->app->db()->fetchAll(
            "SELECT h.tag, COUNT(DISTINCT v.id) AS video_count, COALESCE(SUM(v.view_count), 0) AS view_count, COALESCE(SUM(v.like_count), 0) AS like_count
             FROM hashtags h
             JOIN video_hashtags vh ON vh.hashtag_id = h.id
             JOIN videos v ON v.id = vh.video_id
             WHERE v.status = 'published' AND v.visibility = 'public' AND v.published_at >= ?
             GROUP BY h.id, h.tag
             ORDER BY view_count DESC, like_count DESC, video_count DESC
             LIMIT " . $limit,
            [$since]
        );
        $items = array_map(fn (array $row): array => [
            'tag' => $row['tag'],
            'video_count' => (int) $row['video_count'],
            'view_count' => (int) $row['view_count'],
            'like_count' => (int) $row['like_count'],
        ], $rows);

        return ['items' => $items, 'window_days' => $days];
    }

    public function hashtag(string $tag, ?array $user, int $page, int $perPage): array
    {
        $tag = Str::normalizeTag($tag);

        if ($tag === '') {
            throw ApiException::notFound('Hashtag not found.');
        }

        $hashtag = $this->app->db()->fetch('SELECT * FROM hashtags WHERE tag = ? LIMIT 1', [$tag]);

        if ($hashtag === null) {
            throw ApiException::notFound('Hashtag not found.');
        }

        $offset = ($page - 1) * $perPage;
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active' AND vh.hashtag_id = ?";
        $params = [$hashtag['id']];

        if ($user !== null) {
            $where .= ' AND v.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
                . ' AND v.user_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)'
                . ' AND v.user_id NOT IN (SELECT muted_id FROM mutes WHERE muter_id = ?)';
            $params[] = $user['id'];
            $params[] = $user['id'];
            $params[] = $user['id'];
        }

        $total = (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM video_hashtags vh JOIN videos v ON v.id = vh.video_id JOIN users u ON u.id = v.user_id WHERE ' . $where,
            $params
        );
        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified
             FROM video_hashtags vh JOIN videos v ON v.id = vh.video_id JOIN users u ON u.id = v.user_id
             WHERE ' . $where . '
             ORDER BY v.published_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'hashtag' => [
                'tag' => $hashtag['tag'],
                'video_count' => (int) $hashtag['video_count'],
                'view_count' => (int) $hashtag['view_count'],
            ],
            'items' => $this->app->videos()->payloads($rows, $user['id'] ?? null),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function ranked(?array $user, int $page, int $perPage, bool $affinity, int $windowDays, string $name, ?string $format = null): array
    {
        $candidateLimit = (int) $this->app->config()->get('feed.candidate_limit', 240);
        $format = $this->app->platform()->formatFilter($format);
        [$durationClause, $durationParams] = $this->app->platform()->durationClause($format);
        $since = Str::now(-max(1, $windowDays) * 86400);
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active' AND v.published_at >= ? AND " . $durationClause;
        $params = array_merge([$since], $durationParams);

        if ($user !== null) {
            $where .= ' AND v.user_id <> ?'
                . ' AND v.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
                . ' AND v.user_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)'
                . ' AND v.user_id NOT IN (SELECT muted_id FROM mutes WHERE muter_id = ?)';
            $params[] = $user['id'];
            $params[] = $user['id'];
            $params[] = $user['id'];
            $params[] = $user['id'];
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT v.*, u.username AS author_username, u.display_name AS author_display_name, u.avatar_path AS author_avatar_path, u.verified AS author_verified
             FROM videos v JOIN users u ON u.id = v.user_id
             WHERE ' . $where . ' ORDER BY v.published_at DESC LIMIT ' . $candidateLimit,
            $params
        );
        $scored = $this->app->recommendations()->score($rows, $user, $affinity, $name);
        $rows = $scored['rows'];
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($rows, $offset, $perPage);
        $explanations = [];

        foreach ($sliced as &$row) {
            $explanations[$row['id']] = $row['_explanation'] ?? null;
            unset($row['_score'], $row['_explanation']);
        }

        unset($row);
        $items = $this->app->videos()->payloads($sliced, $user['id'] ?? null);

        foreach ($items as &$item) {
            $item['recommendation'] = $explanations[$item['id']] ?? null;
        }

        unset($item);

        return [
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
            'algorithm' => array_merge($scored['meta'], [
                'name' => $name,
                'window_days' => $windowDays,
                'half_life_hours' => max(1, (int) $this->app->config()->get('feed.half_life_hours', 72)),
                'format' => $format,
                'candidates' => $total,
            ]),
        ];
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            'has_more' => $page * $perPage < $total,
        ];
    }
}
