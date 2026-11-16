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

    public function following(array $user, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active'"
            . ' AND (v.user_id = ? OR v.user_id IN (SELECT following_id FROM follows WHERE follower_id = ?))'
            . ' AND v.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)'
            . ' AND v.user_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)'
            . ' AND v.user_id NOT IN (SELECT muted_id FROM mutes WHERE muter_id = ?)';
        $params = [$user['id'], $user['id'], $user['id'], $user['id'], $user['id']];
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
        ];
    }

    public function forYou(?array $user, int $page, int $perPage): array
    {
        return $this->ranked($user, $page, $perPage, true, (int) $this->app->config()->get('feed.window_days', 14), 'foryou');
    }

    public function explore(?array $user, int $page, int $perPage): array
    {
        return $this->ranked($user, $page, $perPage, false, 7, 'explore');
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

    private function ranked(?array $user, int $page, int $perPage, bool $affinity, int $windowDays, string $name): array
    {
        $candidateLimit = (int) $this->app->config()->get('feed.candidate_limit', 240);
        $since = Str::now(-max(1, $windowDays) * 86400);
        $where = "v.status = 'published' AND v.visibility = 'public' AND u.status = 'active' AND v.published_at >= ?";
        $params = [$since];

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

        $following = [];

        if ($user !== null && $affinity) {
            $followingRows = $this->app->db()->fetchAll('SELECT following_id FROM follows WHERE follower_id = ?', [$user['id']]);
            $following = array_fill_keys(array_column($followingRows, 'following_id'), true);
        }

        $halfLife = max(1, (int) $this->app->config()->get('feed.half_life_hours', 72));
        $now = time();

        foreach ($rows as &$row) {
            $ageHours = max(0, ($now - Str::timestamp($row['published_at'])) / 3600);
            $freshness = exp(-$ageHours / $halfLife);
            $engagement = log1p((int) $row['like_count']) * 1.7
                + log1p((int) $row['comment_count']) * 2.2
                + log1p((int) $row['share_count']) * 1.8
                + log1p((int) $row['save_count']) * 1.3
                + log1p((int) $row['view_count']) * 0.22;
            $rate = ((int) $row['like_count'] + (int) $row['comment_count'] * 2) / max(25, (int) $row['view_count']);
            $score = $freshness * 4.0 + $engagement * 0.8 + min(3.0, $rate * 4.0);

            if ($affinity && isset($following[$row['user_id']])) {
                $score += 2.4;
            }

            $score += (crc32($row['id'] . ($user['id'] ?? 'guest')) % 1000) / 100000;
            $row['_score'] = $score;
        }

        unset($row);

        usort($rows, fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $rows = $this->diversify($rows, (int) $this->app->config()->get('feed.max_per_author', 2));
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($rows, $offset, $perPage);

        foreach ($sliced as &$row) {
            unset($row['_score']);
        }

        unset($row);

        return [
            'items' => $this->app->videos()->payloads($sliced, $user['id'] ?? null),
            'pagination' => $this->pagination($page, $perPage, $total),
            'algorithm' => [
                'name' => $name,
                'window_days' => $windowDays,
                'half_life_hours' => $halfLife,
                'candidates' => $total,
                'signals' => ['freshness', 'likes', 'comments', 'shares', 'saves', 'views', 'author_affinity', 'diversity'],
            ],
        ];
    }

    private function diversify(array $rows, int $maxPerAuthor): array
    {
        if ($maxPerAuthor <= 0 || count($rows) < 3) {
            return $rows;
        }

        $result = [];
        $recent = [];
        $pool = $rows;

        while ($pool !== []) {
            $picked = null;

            foreach ($pool as $index => $row) {
                $author = $row['user_id'];
                $tail = array_slice($recent, -$maxPerAuthor);
                $same = count(array_filter($tail, fn (string $id): bool => $id === $author));

                if ($same < $maxPerAuthor) {
                    $picked = $index;
                    break;
                }
            }

            if ($picked === null) {
                $picked = 0;
            }

            $row = $pool[$picked];
            $result[] = $row;
            $recent[] = $row['user_id'];
            array_splice($pool, $picked, 1);
        }

        return $result;
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
