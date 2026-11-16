<?php

namespace Social\Services;

use Social\App;
use Social\Support\Str;

final class RecommendationService
{
    public function __construct(private readonly App $app)
    {
    }

    public function score(array $rows, ?array $user, bool $affinity, string $name): array
    {
        if ($rows === []) {
            return [
                'rows' => [],
                'meta' => [
                    'name' => $name,
                    'personalized' => $user !== null,
                    'interests' => 0,
                    'content_matches' => 0,
                    'signals' => ['freshness', 'engagement', 'content_topics', 'interest_profile', 'author_affinity', 'seen_history', 'diversity'],
                ],
            ];
        }

        $config = $this->app->config();
        $interestWeight = (float) $config->get('feed.interest_weight', 4.2);
        $contentWeight = (float) $config->get('feed.content_weight', 2.6);
        $followingWeight = (float) $config->get('feed.following_weight', 2.4);
        $seenPenalty = (float) $config->get('feed.seen_penalty', 2.8);
        $negativePenalty = (float) $config->get('feed.negative_penalty', 12.0);
        $halfLife = max(1, (int) $config->get('feed.half_life_hours', 72));
        $vector = $user !== null ? $this->app->analysis()->vector($user['id']) : [];
        $topics = $this->topicsForRows($rows);
        $seen = [];
        $feedback = [];
        $following = [];

        if ($user !== null && $affinity) {
            $followingRows = $this->app->db()->fetchAll('SELECT following_id FROM follows WHERE follower_id = ?', [$user['id']]);
            $following = array_fill_keys(array_column($followingRows, 'following_id'), true);
        }

        if ($user !== null) {
            $seen = $this->app->analysis()->seenIds($user['id']);
            $feedback = $this->app->analysis()->feedbackIds($user['id']);
        }

        $now = time();
        $matches = 0;

        foreach ($rows as &$row) {
            $ageHours = max(0, ($now - Str::timestamp($row['published_at'])) / 3600);
            $freshness = exp(-$ageHours / $halfLife);
            $engagement = log1p((int) $row['like_count']) * 1.7
                + log1p((int) $row['comment_count']) * 2.2
                + log1p((int) $row['share_count']) * 1.8
                + log1p((int) $row['save_count']) * 1.3
                + log1p((int) $row['view_count']) * 0.22;
            $rate = ((int) $row['like_count'] + (int) $row['comment_count'] * 2) / max(25, (int) $row['view_count']);
            $videoTopics = $topics[$row['id']] ?? [];
            $interest = $this->similarity($vector, $videoTopics);
            $reasons = [];

            if ($interest > 0) {
                $reasons[] = 'interest_match';
                $matches++;
            }

            $score = $freshness * 4.0 + $engagement * 0.8 + min(3.0, $rate * 4.0);
            $score += $interest * $interestWeight;
            $score += $this->contentQuality($row, $videoTopics) * $contentWeight;

            if ($affinity && isset($following[$row['user_id']])) {
                $score += $followingWeight;
                $reasons[] = 'following';
            }

            if ($user !== null && isset($seen[$row['id']])) {
                $score -= $seenPenalty;
                $reasons[] = 'already_watched';
            }

            if ($user !== null && isset($feedback[$row['id']])) {
                $score -= $negativePenalty;
                $reasons[] = 'not_interested';
            }

            if ($freshness > 0.6) {
                $reasons[] = 'fresh';
            }

            if ($engagement > 4.0) {
                $reasons[] = 'high_engagement';
            }

            $score += (crc32($row['id'] . ($user['id'] ?? 'guest')) % 1000) / 100000;
            $row['_score'] = $score;
            $row['_explanation'] = [
                'score' => round($score, 4),
                'interest_match' => round($interest, 4),
                'matched_topics' => $this->matchedTopics($vector, $videoTopics),
                'reasons' => $reasons,
            ];
        }

        unset($row);

        usort($rows, fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $rows = $this->diversify($rows, (int) $config->get('feed.max_per_author', 2));

        return [
            'rows' => $rows,
            'meta' => [
                'name' => $name,
                'personalized' => $user !== null && $vector !== [],
                'interests' => count($vector),
                'content_matches' => $matches,
                'signals' => ['freshness', 'engagement', 'content_topics', 'interest_profile', 'author_affinity', 'seen_history', 'diversity'],
                'weights' => [
                    'interest' => $interestWeight,
                    'content' => $contentWeight,
                    'author_affinity' => $affinity ? $followingWeight : 0.0,
                    'seen_penalty' => $seenPenalty,
                ],
            ],
        ];
    }

    private function topicsForRows(array $rows): array
    {
        $ids = array_values(array_unique(array_map(fn (array $row): string => $row['id'], $rows)));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $topicRows = $this->app->db()->fetchAll(
            'SELECT video_id, topic, weight FROM video_topics WHERE video_id IN (' . $placeholders . ')',
            $ids
        );
        $topics = [];

        foreach ($topicRows as $row) {
            $topics[$row['video_id']][$row['topic']] = (float) $row['weight'];
        }

        return $topics;
    }

    private function similarity(array $vector, array $topics): float
    {
        if ($vector === [] || $topics === []) {
            return 0.0;
        }

        $dot = 0.0;
        $vectorNorm = 0.0;
        $topicNorm = 0.0;

        foreach ($vector as $topic => $weight) {
            $vectorNorm += $weight * $weight;

            if (isset($topics[$topic])) {
                $dot += $weight * $topics[$topic];
            }
        }

        foreach ($topics as $weight) {
            $topicNorm += $weight * $weight;
        }

        if ($dot <= 0 || $vectorNorm <= 0 || $topicNorm <= 0) {
            return 0.0;
        }

        return min(1.0, $dot / sqrt($vectorNorm * $topicNorm));
    }

    private function matchedTopics(array $vector, array $topics): array
    {
        $matches = [];

        foreach ($topics as $topic => $weight) {
            if (isset($vector[$topic])) {
                $matches[$topic] = round($vector[$topic] * $weight, 4);
            }
        }

        arsort($matches);

        return array_slice(array_keys($matches), 0, 4);
    }

    private function contentQuality(array $row, array $topics): float
    {
        $quality = 0.0;

        if (isset($topics['high_motion'])) {
            $quality += 0.12;
        }

        if (isset($topics['fast_cuts'])) {
            $quality += 0.1;
        }

        if (isset($topics['vertical_video'])) {
            $quality += 0.08;
        }

        if ((int) $row['duration'] > 0) {
            $quality += 0.05;
        }

        return min(1.0, $quality);
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
}
