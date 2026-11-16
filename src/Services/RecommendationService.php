<?php

namespace Social\Services;

use Social\App;
use Social\Support\Str;

final class RecommendationService
{
    private const ENGAGEMENT_COLUMNS = [
        'like' => 'likes',
        'comment' => 'comments',
        'share' => 'shares',
        'save' => 'saves',
        'follow' => 'follows',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function score(array $rows, ?array $user, bool $affinity, string $name, ?string $sessionId = null, int $slateSize = 0): array
    {
        $config = $this->app->config();
        $session = $this->sessionContext($user, $sessionId);

        if ($rows === []) {
            return [
                'rows' => [],
                'meta' => [
                    'name' => $name,
                    'personalized' => $user !== null,
                    'interests' => 0,
                    'content_matches' => 0,
                    'signals' => $this->signals(),
                    'session' => $session['meta'],
                ],
            ];
        }

        $interestWeight = (float) $config->get('feed.interest_weight', 4.2);
        $contentWeight = (float) $config->get('feed.content_weight', 1.8);
        $followingWeight = (float) $config->get('feed.following_weight', 2.0);
        $seenPenalty = (float) $config->get('feed.seen_penalty', 1.6);
        $negativePenalty = (float) $config->get('feed.negative_penalty', 40.0);
        $retentionWeight = (float) $config->get('feed.retention_weight', 5.4);
        $watchWeight = (float) $config->get('feed.expected_watch_weight', 2.6);
        $rewatchWeight = (float) $config->get('feed.rewatch_weight', 1.8);
        $hookWeight = (float) $config->get('feed.hook_weight', 3.4);
        $engagementWeight = (float) $config->get('feed.engagement_weight', 2.0);
        $skipPenalty = (float) $config->get('feed.skip_penalty', 9.0);
        $sessionSkipPenalty = (float) $config->get('feed.session_skip_penalty', 4.0);
        $repeatPenalty = (float) $config->get('feed.repeat_penalty', 10.0);
        $topicFatigueWeight = (float) $config->get('feed.topic_fatigue_weight', 1.6);
        $authorFatigueWeight = (float) $config->get('feed.author_fatigue_weight', 1.2);
        $explorationWeight = (float) $config->get('feed.exploration_weight', 1.7);
        $halfLife = max(1, (int) $config->get('feed.half_life_hours', 72));
        $completionRatio = (float) $config->get('feed.completion_ratio', 0.9);
        $priorStarts = max(1, (int) $config->get('feed.prior_starts', 20));
        $priorCompletion = (float) $config->get('feed.prior_completion', 0.35);
        $priorSkip = (float) $config->get('feed.prior_skip', 0.18);
        $priorEngagement = (float) $config->get('feed.prior_engagement', 0.06);
        $priorReplay = (float) $config->get('feed.prior_replay', 0.05);
        $minImpressions = max(1, (int) $config->get('feed.exploration_min_impressions', 8));
        $vector = $user !== null ? $this->app->analysis()->vector($user['id']) : [];
        $topics = $this->topicsForRows($rows);
        $seen = [];
        $feedback = [];
        $following = [];
        $topicStats = [];
        $authorStats = [];
        $recentImpressions = [];

        if ($user !== null) {
            $seen = $this->app->analysis()->seenIds($user['id']);
            $feedback = $this->app->analysis()->feedbackIds($user['id']);
            $topicStats = $this->topicStats($user['id']);
            $authorStats = $this->authorStats($user['id']);
            $recentImpressions = $this->recentImpressions($user['id']);
        }

        if ($user !== null && $affinity) {
            $followingRows = $this->app->db()->fetchAll('SELECT following_id FROM follows WHERE follower_id = ?', [$user['id']]);
            $following = array_fill_keys(array_column($followingRows, 'following_id'), true);
        }

        $performance = $this->performanceFor($rows);
        $global = $this->globalStats();
        $globalCompletion = $this->smoothed((int) $global['completions'], (int) $global['starts'], $priorCompletion, $priorStarts);
        $globalSkip = $this->smoothed((int) $global['skips'], (int) $global['starts'], $priorSkip, $priorStarts);
        $globalReplay = $this->smoothed((int) $global['replays'], max(1, (int) $global['impressions']), $priorReplay, 10);
        $now = time();
        $matches = 0;
        $totalImpressions = (int) $global['impressions'];

        foreach ($rows as &$row) {
            $perf = $performance[$row['id']] ?? $this->emptyPerformance();
            $videoTopics = $topics[$row['id']] ?? [];
            $primaryTopic = $videoTopics === [] ? null : array_key_first($videoTopics);
            $interest = $this->similarity($vector, $videoTopics);
            $stats = $this->topicAggregate($videoTopics, $topicStats);
            $authorStat = $authorStats[$row['user_id']] ?? null;
            $ageHours = max(0, ($now - Str::timestamp($row['published_at'])) / 3600);
            $freshness = exp(-$ageHours / $halfLife);
            $starts = (int) $perf['starts'];
            $impressions = (int) $perf['impressions'];
            $videoCompletion = $this->smoothed((int) $perf['completions'], $starts, $globalCompletion, $priorStarts);
            $videoSkip = $this->smoothed((int) $perf['skips'], $starts, $globalSkip, $priorStarts);
            $topicCompletion = $this->smoothed($stats['completions'], $stats['starts'], $globalCompletion, 8);
            $topicSkip = $this->smoothed($stats['skips'], $stats['starts'], $globalSkip, 8);
            $topicReplay = $this->smoothed($stats['replays'], max(1, $stats['starts']), $globalReplay, 8);
            $authorCompletion = $authorStat === null
                ? $globalCompletion
                : $this->smoothed((int) $authorStat['completions'], (int) $authorStat['starts'], $globalCompletion, 10);
            $authorSkip = $authorStat === null
                ? $globalSkip
                : $this->smoothed((int) $authorStat['skips'], (int) $authorStat['starts'], $globalSkip, 10);
            $authorRatio = $authorStat === null ? $globalCompletion : (float) $authorStat['avg_ratio'];
            $topicRatio = $stats['starts'] > 0 ? (float) $stats['avg_ratio'] : $globalCompletion;
            $engagementCount = max((int) $perf['likes'], (int) $row['like_count'])
                + max((int) $perf['comments'], (int) $row['comment_count']) * 1.6
                + max((int) $perf['shares'], (int) $row['share_count']) * 2.2
                + max((int) $perf['saves'], (int) $row['save_count']) * 1.7
                + (int) $perf['follows'] * 2.5;
            $exposure = max(1, max($starts, (int) $row['view_count']));
            $videoEngagement = $this->smoothed($engagementCount, $exposure, $priorEngagement, 25);
            $predictedCompletion = $this->clamp(0.45 * $topicCompletion + 0.25 * $authorCompletion + 0.2 * $videoCompletion + 0.1 * $authorRatio, 0.01, 0.99);
            $predictedSkip = $this->clamp(0.45 * $topicSkip + 0.25 * $authorSkip + 0.2 * $videoSkip + 0.1 * $session['skip_rate'], 0.0, 0.96);
            $predictedEngagement = $this->clamp(0.7 * $videoEngagement + 0.3 * $interest, 0.0, 0.95);
            $predictedReplay = $this->clamp(0.5 * $this->smoothed((int) $perf['replays'], $impressions, $globalReplay, 10) + 0.3 * $topicReplay + 0.2 * $globalReplay, 0.0, 0.9);
            $expectedRatio = $this->clamp(0.55 * $predictedCompletion + 0.3 * $topicRatio + 0.15 * $globalCompletion, 0.02, 1.0);
            $duration = max(1, (int) $row['duration']);
            $expectedSeconds = $expectedRatio * $duration;
            $watchValue = log1p($expectedSeconds);
            $quality = $this->contentQuality($row, $videoTopics);
            $hook = min(1.0, $quality + (isset($videoTopics['fast_cuts']) ? 0.18 : 0.0) + (isset($videoTopics['high_motion']) ? 0.12 : 0.0));
            $ucb = min(2.0, sqrt(log($totalImpressions + 2) / max(1, $impressions + 1)));
            $creatorNew = isset($authorStats[$row['user_id']]) ? 0.0 : 1.0;
            $unseen = isset($seen[$row['id']]) ? 0.0 : 1.0;
            $exploration = 0.55 * $ucb + 0.25 * $creatorNew + 0.2 * $unseen;
            $sessionTopicCount = $primaryTopic === null ? 0 : (int) ($session['topics'][$primaryTopic] ?? 0);
            $sessionAuthorCount = (int) ($session['authors'][$row['user_id']] ?? 0);
            $topicFatigue = 1 - exp(-$sessionTopicCount / 2);
            $authorFatigue = 1 - exp(-$sessionAuthorCount / 2);
            $sessionRepeat = (int) ($session['impressions'][$row['id']] ?? 0);
            $historyRepeat = (int) ($recentImpressions[$row['id']] ?? 0);
            $repeatScore = min(4.0, $sessionRepeat * 1.5 + min(2, $historyRepeat) * 0.5);
            $reasons = [];

            if ($interest > 0) {
                $reasons[] = 'interest_match';
                $matches++;
            }

            if ($affinity && isset($following[$row['user_id']])) {
                $reasons[] = 'following';
            }

            if ($freshness > 0.6) {
                $reasons[] = 'fresh';
            }

            if ($predictedCompletion > $completionRatio) {
                $reasons[] = 'high_completion';
            }

            if ($predictedReplay > 0.12) {
                $reasons[] = 'rewatchable';
            }

            if ($predictedSkip > 0.55) {
                $reasons[] = 'skip_risk';
            }

            if ($impressions < $minImpressions) {
                $reasons[] = 'exploration';
            }

            if ($sessionTopicCount >= 2) {
                $reasons[] = 'topic_fatigue';
            }

            if ($sessionAuthorCount >= 2) {
                $reasons[] = 'author_fatigue';
            }

            if ($sessionRepeat > 0 || $historyRepeat > 0) {
                $reasons[] = 'repeat_exposure';
            }

            if ($user !== null && isset($seen[$row['id']])) {
                $reasons[] = 'already_watched';
            }

            if ($user !== null && isset($feedback[$row['id']])) {
                $reasons[] = 'not_interested';
            }

            $score = $predictedCompletion * $retentionWeight
                + $watchValue * $watchWeight
                + $predictedReplay * $rewatchWeight
                + (1 - $predictedSkip) * $hook * $hookWeight
                + $predictedEngagement * $engagementWeight
                + $interest * $interestWeight
                + $quality * $contentWeight
                + $exploration * $explorationWeight;
            $score += $affinity && isset($following[$row['user_id']]) ? $followingWeight : 0;
            $score -= $predictedSkip * $skipPenalty * (1 + $session['skip_rate'] * $sessionSkipPenalty);
            $score -= $topicFatigue * $topicFatigueWeight + $authorFatigue * $authorFatigueWeight;
            $score -= $repeatScore * $repeatPenalty;

            if ($user !== null && isset($seen[$row['id']])) {
                $score -= $seenPenalty;
            }

            if ($user !== null && isset($feedback[$row['id']])) {
                $score -= $negativePenalty;
            }

            $score += (crc32($row['id'] . ($user['id'] ?? 'guest')) % 1000) / 100000;
            $row['_score'] = $score;
            $row['_primary_topic'] = $primaryTopic;
            $row['_exploratory'] = $impressions < $minImpressions || $creatorNew > 0;
            $row['_explanation'] = [
                'score' => round($score, 4),
                'interest_match' => round($interest, 4),
                'matched_topics' => $this->matchedTopics($vector, $videoTopics),
                'predicted_completion' => round($predictedCompletion, 4),
                'predicted_skip' => round($predictedSkip, 4),
                'predicted_engagement' => round($predictedEngagement, 4),
                'expected_watch_seconds' => round($expectedSeconds, 2),
                'exploration' => round($exploration, 4),
                'fatigue' => round($topicFatigue + $authorFatigue + $repeatScore, 4),
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        unset($row);

        $rows = $this->buildSlate($rows, $session, $slateSize);
        $personalized = $user !== null && ($vector !== [] || $topicStats !== [] || $authorStats !== []);

        return [
            'rows' => $rows,
            'meta' => [
                'name' => $name,
                'personalized' => $personalized,
                'interests' => count($vector),
                'content_matches' => $matches,
                'signals' => $this->signals(),
                'weights' => [
                    'retention' => $retentionWeight,
                    'expected_watch' => $watchWeight,
                    'rewatch' => $rewatchWeight,
                    'hook' => $hookWeight,
                    'engagement' => $engagementWeight,
                    'interest' => $interestWeight,
                    'author_affinity' => $affinity ? $followingWeight : 0.0,
                    'skip_penalty' => $skipPenalty,
                    'exploration' => $explorationWeight,
                ],
                'session' => $session['meta'],
            ],
        ];
    }

    public function recordImpressions(?array $user, array $items, string $sessionId, string $source): void
    {
        if ($items === []) {
            return;
        }

        $ids = array_values(array_unique(array_map(fn (array $item): string => (string) $item['id'], $items)));
        $now = Str::now();
        $this->app->db()->transaction(function () use ($user, $items, $sessionId, $source, $ids, $now): void {
            foreach ($items as $position => $item) {
                $this->app->db()->insert('feed_impressions', [
                    'id' => Str::id(),
                    'user_id' => $user['id'] ?? null,
                    'session_id' => $sessionId,
                    'video_id' => $item['id'],
                    'source' => $source,
                    'position' => $position,
                    'created_at' => $now,
                ]);
            }

            $this->ensurePerformanceRows($ids, $now);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $this->app->db()->run(
                'UPDATE video_performance SET impressions = impressions + 1, updated_at = ? WHERE video_id IN (' . $placeholders . ')',
                array_merge([$now], $ids)
            );
        });
    }

    public function recordEngagement(string $videoId, string $type, int $delta = 1): void
    {
        $column = self::ENGAGEMENT_COLUMNS[$type] ?? null;

        if ($column === null) {
            return;
        }

        $now = Str::now();
        $this->ensurePerformanceRows([$videoId], $now);

        if ($delta >= 0) {
            $this->app->db()->run(
                'UPDATE video_performance SET `' . $column . '` = `' . $column . '` + ?, updated_at = ? WHERE video_id = ?',
                [$delta, $now, $videoId]
            );

            return;
        }

        $this->app->db()->run(
            'UPDATE video_performance SET `' . $column . '` = CASE WHEN `' . $column . '` > 0 THEN `' . $column . '` - 1 ELSE 0 END, updated_at = ? WHERE video_id = ?',
            [$now, $videoId]
        );
    }

    public function recordPlayback(string $videoId, bool $started, bool $completed, bool $skipped, bool $replayed, int $watchSeconds): void
    {
        $now = Str::now();
        $this->ensurePerformanceRows([$videoId], $now);
        $this->app->db()->run(
            'UPDATE video_performance SET starts = starts + ?, completions = completions + ?, skips = skips + ?, replays = replays + ?, watch_seconds = watch_seconds + ?, updated_at = ? WHERE video_id = ?',
            [
                $started ? 1 : 0,
                $completed ? 1 : 0,
                $skipped ? 1 : 0,
                $replayed ? 1 : 0,
                max(0, $watchSeconds),
                $now,
                $videoId,
            ]
        );
    }

    private function sessionContext(?array $user, ?string $sessionId): array
    {
        $config = $this->app->config();
        $window = max(5, (int) $config->get('feed.session_window_minutes', 45));
        $since = Str::now(-$window * 60);
        $sessionId = $sessionId === null ? null : mb_substr(trim($sessionId), 0, 64, 'UTF-8');
        $impressions = [];
        $topics = [];
        $authors = [];
        $starts = 0;
        $completions = 0;
        $skips = 0;
        $replays = 0;
        $avgRatio = 0.0;

        if ($sessionId !== null && $sessionId !== '') {
            $rows = $this->app->db()->fetchAll(
                'SELECT video_id, COUNT(*) AS total FROM feed_impressions WHERE session_id = ? AND created_at >= ? GROUP BY video_id',
                [$sessionId, $since]
            );

            foreach ($rows as $row) {
                $impressions[$row['video_id']] = (int) $row['total'];
            }

            $topicRows = $this->app->db()->fetchAll(
                'SELECT vt.topic, COUNT(*) AS total FROM feed_impressions i JOIN video_topics vt ON vt.video_id = i.video_id WHERE i.session_id = ? AND i.created_at >= ? GROUP BY vt.topic',
                [$sessionId, $since]
            );

            foreach ($topicRows as $row) {
                $topics[$row['topic']] = (int) $row['total'];
            }

            $authorRows = $this->app->db()->fetchAll(
                'SELECT v.user_id, COUNT(*) AS total FROM feed_impressions i JOIN videos v ON v.id = i.video_id WHERE i.session_id = ? AND i.created_at >= ? GROUP BY v.user_id',
                [$sessionId, $since]
            );

            foreach ($authorRows as $row) {
                $authors[$row['user_id']] = (int) $row['total'];
            }

            $playback = $this->app->db()->fetch(
                'SELECT COUNT(*) AS starts, COALESCE(SUM(completed), 0) AS completions, COALESCE(SUM(skipped), 0) AS skips, COALESCE(SUM(replayed), 0) AS replays, COALESCE(AVG(watch_ratio), 0) AS avg_ratio FROM watch_signals WHERE session_id = ? AND created_at >= ?',
                [$sessionId, $since]
            );
            $starts = (int) ($playback['starts'] ?? 0);
            $completions = (int) ($playback['completions'] ?? 0);
            $skips = (int) ($playback['skips'] ?? 0);
            $replays = (int) ($playback['replays'] ?? 0);
            $avgRatio = (float) ($playback['avg_ratio'] ?? 0);
        }

        if ($starts === 0 && $user !== null) {
            $recent = $this->app->db()->fetchAll(
                'SELECT completed, skipped, watch_ratio FROM watch_signals WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
                [$user['id']]
            );

            foreach ($recent as $row) {
                $starts++;
                $completions += (int) $row['completed'];
                $skips += (int) $row['skipped'];
                $avgRatio += (float) $row['watch_ratio'];
            }

            if ($starts > 0) {
                $avgRatio /= $starts;
            }
        }

        $skipRate = $starts > 0 ? $skips / $starts : 0.0;
        $completionRate = $starts > 0 ? $completions / $starts : 0.0;

        return [
            'impressions' => $impressions,
            'topics' => $topics,
            'authors' => $authors,
            'starts' => $starts,
            'completions' => $completions,
            'skips' => $skips,
            'replays' => $replays,
            'skip_rate' => $skipRate,
            'completion_rate' => $completionRate,
            'avg_ratio' => $starts > 0 ? $avgRatio : 0.0,
            'meta' => [
                'id' => $sessionId,
                'impressions' => array_sum($impressions),
                'starts' => $starts,
                'completions' => $completions,
                'skips' => $skips,
                'replays' => $replays,
                'skip_rate' => round($skipRate, 4),
                'completion_rate' => round($completionRate, 4),
                'avg_watch_ratio' => round($starts > 0 ? $avgRatio : 0.0, 4),
            ],
        ];
    }

    private function topicStats(string $userId): array
    {
        $days = max(1, (int) $this->app->config()->get('feed.history_days', 120));
        $since = Str::now(-$days * 86400);
        $rows = $this->app->db()->fetchAll(
            'SELECT vt.topic, COUNT(DISTINCT ws.id) AS starts, COALESCE(SUM(ws.completed), 0) AS completions, COALESCE(SUM(ws.skipped), 0) AS skips, COALESCE(SUM(ws.replayed), 0) AS replays, COALESCE(AVG(ws.watch_ratio), 0) AS avg_ratio
             FROM watch_signals ws JOIN video_topics vt ON vt.video_id = ws.video_id
             WHERE ws.user_id = ? AND ws.created_at >= ?
             GROUP BY vt.topic',
            [$userId, $since]
        );
        $stats = [];

        foreach ($rows as $row) {
            $stats[$row['topic']] = [
                'starts' => (int) $row['starts'],
                'completions' => (int) $row['completions'],
                'skips' => (int) $row['skips'],
                'replays' => (int) $row['replays'],
                'avg_ratio' => (float) $row['avg_ratio'],
            ];
        }

        return $stats;
    }

    private function authorStats(string $userId): array
    {
        $days = max(1, (int) $this->app->config()->get('feed.history_days', 120));
        $since = Str::now(-$days * 86400);
        $rows = $this->app->db()->fetchAll(
            'SELECT v.user_id, COUNT(DISTINCT ws.id) AS starts, COALESCE(SUM(ws.completed), 0) AS completions, COALESCE(SUM(ws.skipped), 0) AS skips, COALESCE(AVG(ws.watch_ratio), 0) AS avg_ratio
             FROM watch_signals ws JOIN videos v ON v.id = ws.video_id
             WHERE ws.user_id = ? AND ws.created_at >= ?
             GROUP BY v.user_id',
            [$userId, $since]
        );
        $stats = [];

        foreach ($rows as $row) {
            $stats[$row['user_id']] = [
                'starts' => (int) $row['starts'],
                'completions' => (int) $row['completions'],
                'skips' => (int) $row['skips'],
                'avg_ratio' => (float) $row['avg_ratio'],
            ];
        }

        return $stats;
    }

    private function recentImpressions(string $userId): array
    {
        $days = max(1, (int) $this->app->config()->get('feed.recent_impression_days', 3));
        $rows = $this->app->db()->fetchAll(
            'SELECT video_id, COUNT(*) AS total FROM feed_impressions WHERE user_id = ? AND created_at >= ? GROUP BY video_id',
            [$userId, Str::now(-$days * 86400)]
        );
        $items = [];

        foreach ($rows as $row) {
            $items[$row['video_id']] = (int) $row['total'];
        }

        return $items;
    }

    private function performanceFor(array $rows): array
    {
        $ids = array_values(array_unique(array_map(fn (array $row): string => (string) $row['id'], $rows)));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $records = $this->app->db()->fetchAll(
            'SELECT * FROM video_performance WHERE video_id IN (' . $placeholders . ')',
            $ids
        );
        $performance = [];

        foreach ($records as $record) {
            $performance[$record['video_id']] = $record;
        }

        return $performance;
    }

    private function globalStats(): array
    {
        $row = $this->app->db()->fetch(
            'SELECT COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(starts), 0) AS starts, COALESCE(SUM(completions), 0) AS completions, COALESCE(SUM(skips), 0) AS skips, COALESCE(SUM(replays), 0) AS replays, COALESCE(SUM(watch_seconds), 0) AS watch_seconds FROM video_performance'
        );

        return $row === null ? ['impressions' => 0, 'starts' => 0, 'completions' => 0, 'skips' => 0, 'replays' => 0, 'watch_seconds' => 0] : $row;
    }

    private function ensurePerformanceRows(array $ids, string $now): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $existing = $this->app->db()->fetchAll(
            'SELECT video_id FROM video_performance WHERE video_id IN (' . $placeholders . ')',
            $ids
        );
        $known = array_fill_keys(array_column($existing, 'video_id'), true);

        foreach ($ids as $id) {
            if (isset($known[$id])) {
                continue;
            }

            $this->app->db()->insert('video_performance', [
                'video_id' => $id,
                'impressions' => 0,
                'starts' => 0,
                'completions' => 0,
                'skips' => 0,
                'replays' => 0,
                'watch_seconds' => 0,
                'likes' => 0,
                'comments' => 0,
                'shares' => 0,
                'saves' => 0,
                'follows' => 0,
                'updated_at' => $now,
            ]);
        }
    }

    private function topicAggregate(array $topics, array $stats): array
    {
        $aggregate = ['starts' => 0, 'completions' => 0, 'skips' => 0, 'replays' => 0, 'avg_ratio' => 0.0, 'weight' => 0.0];

        foreach ($topics as $topic => $weight) {
            $stat = $stats[$topic] ?? null;

            if ($stat === null) {
                continue;
            }

            $aggregate['starts'] += $stat['starts'];
            $aggregate['completions'] += $stat['completions'];
            $aggregate['skips'] += $stat['skips'];
            $aggregate['replays'] += $stat['replays'];
            $aggregate['avg_ratio'] += $stat['avg_ratio'] * $weight;
            $aggregate['weight'] += $weight;
        }

        if ($aggregate['weight'] > 0) {
            $aggregate['avg_ratio'] /= $aggregate['weight'];
        }

        return $aggregate;
    }

    private function topicsForRows(array $rows): array
    {
        $ids = array_values(array_unique(array_map(fn (array $row): string => (string) $row['id'], $rows)));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $topicRows = $this->app->db()->fetchAll(
            'SELECT video_id, topic, weight FROM video_topics WHERE video_id IN (' . $placeholders . ') ORDER BY weight DESC',
            $ids
        );
        $topics = [];

        foreach ($topicRows as $row) {
            $topics[$row['video_id']][$row['topic']] = (float) $row['weight'];
        }

        return $topics;
    }

    private function buildSlate(array $rows, array $session, int $slateSize): array
    {
        usort($rows, fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $config = $this->app->config();
        $rate = (float) $config->get('feed.exploration_rate', 0.16);
        $interval = max(2, (int) round(1 / max(0.01, min(0.5, $rate))));
        $maxPerAuthor = max(1, (int) $config->get('feed.max_per_author', 2));
        $maxAuthorTotal = max($maxPerAuthor, (int) $config->get('feed.max_author_total', 4));
        $maxSameTopic = max(1, (int) $config->get('feed.max_same_topic', 2));
        $topicFatigueWeight = (float) $config->get('feed.topic_fatigue_weight', 1.6);
        $authorFatigueWeight = (float) $config->get('feed.author_fatigue_weight', 1.2);
        $selected = [];
        $pool = $rows;
        $recentAuthors = [];
        $recentTopics = [];
        $authorCounts = [];
        $topicCounts = [];

        while ($pool !== []) {
            $position = count($selected);
            $bestIndex = null;
            $bestScore = -INF;
            $bestRaw = -INF;
            $exploreIndex = null;
            $exploreScore = -INF;
            $fallbackIndex = null;
            $fallbackScore = -INF;

            foreach ($pool as $index => $row) {
                $author = (string) $row['user_id'];
                $topic = $row['_primary_topic'];
                $adjusted = $row['_score']
                    - (($topic === null ? 0 : ($topicCounts[$topic] ?? 0)) * $topicFatigueWeight)
                    - (($authorCounts[$author] ?? 0) * $authorFatigueWeight);

                if ($adjusted > $fallbackScore) {
                    $fallbackScore = $adjusted;
                    $fallbackIndex = $index;
                }

                if (($authorCounts[$author] ?? 0) >= $maxAuthorTotal) {
                    continue;
                }

                if ($row['_exploratory'] && $adjusted > $exploreScore) {
                    $exploreScore = $adjusted;
                    $exploreIndex = $index;
                }

                if (!$this->windowAllows($recentAuthors, $author, $maxPerAuthor)) {
                    continue;
                }

                if ($topic !== null && !$this->windowAllows($recentTopics, $topic, $maxSameTopic)) {
                    continue;
                }

                if ($adjusted > $bestScore) {
                    $bestScore = $adjusted;
                    $bestIndex = $index;
                }

                $bestRaw = max($bestRaw, $row['_score']);
            }

            if ($bestIndex === null) {
                $bestIndex = $fallbackIndex;
            }

            if ($bestIndex === null) {
                break;
            }

            $useExploration = $position < max(1, $slateSize)
                && $position > 0
                && $position % $interval === $interval - 1
                && $exploreIndex !== null
                && $pool[$exploreIndex]['_score'] >= $bestRaw * 0.35;

            if ($useExploration) {
                $bestIndex = $exploreIndex;
            }

            $row = $pool[$bestIndex];
            $row['_slate_position'] = $position;
            $row['_explanation']['slate_position'] = $position;

            if ($useExploration) {
                $row['_explanation']['reasons'][] = 'exploration_slot';
                $row['_explanation']['reasons'] = array_values(array_unique($row['_explanation']['reasons']));
            }

            $selected[] = $row;
            $author = (string) $row['user_id'];
            $authorCounts[$author] = ($authorCounts[$author] ?? 0) + 1;
            $recentAuthors[] = $author;
            $recentAuthors = array_slice($recentAuthors, -$maxPerAuthor);
            $topic = $row['_primary_topic'];

            if ($topic !== null) {
                $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
                $recentTopics[] = $topic;
                $recentTopics = array_slice($recentTopics, -$maxSameTopic);
            }

            array_splice($pool, $bestIndex, 1);
        }

        return $selected;
    }

    private function windowAllows(array $window, string $value, int $limit): bool
    {
        $count = 0;

        foreach ($window as $item) {
            if ($item === $value) {
                $count++;
            }
        }

        return $count < $limit;
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

        if (isset($topics['with_audio'])) {
            $quality += 0.05;
        }

        if (isset($topics['colorful']) || isset($topics['warm_palette'])) {
            $quality += 0.04;
        }

        if ((int) $row['duration'] > 0) {
            $quality += 0.05;
        }

        return min(1.0, $quality);
    }

    private function smoothed(int|float $successes, int|float $total, float $prior, int $strength): float
    {
        return ($successes + $prior * $strength) / max(0.001, $total + $strength);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function emptyPerformance(): array
    {
        return [
            'impressions' => 0,
            'starts' => 0,
            'completions' => 0,
            'skips' => 0,
            'replays' => 0,
            'watch_seconds' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'saves' => 0,
            'follows' => 0,
        ];
    }

    private function signals(): array
    {
        return [
            'predicted_completion',
            'expected_watch_time',
            'rewatch_probability',
            'skip_probability',
            'hook_quality',
            'engagement_probability',
            'interest_profile',
            'author_affinity',
            'session_context',
            'topic_fatigue',
            'author_fatigue',
            'repeat_exposure',
            'exploration',
            'slate_diversity',
        ];
    }
}
