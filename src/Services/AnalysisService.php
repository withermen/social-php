<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Support\Str;
use Social\Support\Validator;

final class AnalysisService
{
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'you', 'are', 'was', 'were', 'have', 'has', 'had',
        'not', 'but', 'all', 'any', 'can', 'will', 'just', 'into', 'about', 'more', 'than', 'then', 'them', 'they',
        'bir', 'ile', 'için', 'olan', 'olarak', 'daha', 'çok', 'gibi', 'bu', 'şu', 've', 'ile', 'ama', 'ben', 'sen',
        'biz', 'siz', 'onlar', 'de', 'da', 'ki', 'mi', 'mı', 'the', 'video', 'clip', 'shorts', 'short', 'youtube',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->app->config()->get('analysis.enabled', true);
    }

    public function analyze(string $videoId, bool $force = false): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $video = $this->app->db()->fetch('SELECT * FROM videos WHERE id = ? LIMIT 1', [$videoId]);

        if ($video === null) {
            throw ApiException::notFound('Video not found.');
        }

        $existing = $this->app->db()->fetch('SELECT * FROM video_analysis WHERE video_id = ? LIMIT 1', [$videoId]);

        if (!$force && $existing !== null && $existing['status'] === 'complete') {
            return $this->payload($existing, $video);
        }

        $path = $this->app->storage()->path($video['storage_path']);
        $metadata = is_file($path) ? $this->app->media()->metadata($path) : [];
        $duration = (int) ($video['duration'] > 0 ? $video['duration'] : round((float) ($metadata['duration'] ?? 0)));
        $hasAudio = (bool) ($metadata['has_audio'] ?? false);
        $audio = $hasAudio && is_file($path) ? $this->app->media()->audioStats($path, $duration) : null;
        $scenes = is_file($path) ? $this->app->media()->sceneCuts($path, (float) $this->app->config()->get('analysis.scene_threshold', 0.32)) : [];
        $directory = $this->app->storage()->path('tmp/analysis-' . $videoId . '-' . Str::token(3));
        $frames = is_file($path) ? $this->app->media()->frames(
            $path,
            $directory,
            $duration,
            (int) $this->app->config()->get('analysis.frame_samples', 5)
        ) : [];
        $visual = $this->visualFeatures($frames);
        $this->removeDirectory($directory);
        $tags = $this->app->db()->fetchAll(
            'SELECT h.tag FROM video_hashtags vh JOIN hashtags h ON h.id = vh.hashtag_id WHERE vh.video_id = ?',
            [$videoId]
        );
        $cutRate = $duration > 0 ? count($scenes) / $duration : 0.0;
        $topics = $this->buildTopics($video, array_column($tags, 'tag'), $visual, $audio, $duration, count($scenes), $cutRate);
        $features = [
            'width' => (int) ($metadata['width'] ?? $video['width']),
            'height' => (int) ($metadata['height'] ?? $video['height']),
            'fps' => round((float) ($metadata['fps'] ?? 0), 3),
            'bitrate' => (int) ($metadata['bitrate'] ?? 0),
            'video_codec' => $metadata['video_codec'] ?? null,
            'audio_codec' => $metadata['audio_codec'] ?? null,
            'channels' => (int) ($metadata['channels'] ?? 0),
            'sample_rate' => (int) ($metadata['sample_rate'] ?? 0),
            'scene_times' => array_slice($scenes, 0, 100),
            'frame_count' => count($frames),
        ];
        $now = Str::now();
        $record = [
            'video_id' => $videoId,
            'status' => 'complete',
            'engine' => 'ffmpeg-gd',
            'has_audio' => $hasAudio ? 1 : 0,
            'fps' => round((float) ($metadata['fps'] ?? 0), 3),
            'bitrate' => (int) ($metadata['bitrate'] ?? 0),
            'loudness' => $audio['loudness'] ?? null,
            'peak' => $audio['peak'] ?? null,
            'silence_ratio' => round((float) ($audio['silence_ratio'] ?? 0), 4),
            'scene_count' => count($scenes),
            'cut_rate' => round($cutRate, 4),
            'motion' => round((float) ($visual['motion'] ?? 0), 4),
            'brightness' => round((float) ($visual['brightness'] ?? 0), 4),
            'saturation' => round((float) ($visual['saturation'] ?? 0), 4),
            'contrast' => round((float) ($visual['contrast'] ?? 0), 4),
            'colorfulness' => round((float) ($visual['colorfulness'] ?? 0), 4),
            'dominant_color' => $visual['dominant_color'] ?? null,
            'palette' => json_encode($visual['palette'] ?? [], JSON_UNESCAPED_SLASHES),
            'perceptual_hash' => $visual['perceptual_hash'] ?? null,
            'topics' => json_encode($topics, JSON_UNESCAPED_SLASHES),
            'features' => json_encode($features, JSON_UNESCAPED_SLASHES),
            'analyzed_at' => $now,
            'updated_at' => $now,
        ];

        $this->app->db()->transaction(function () use ($videoId, $existing, $record, $topics, $now): void {
            if ($existing === null) {
                $record['created_at'] = $now;
                $this->app->db()->insert('video_analysis', $record);
            } else {
                $this->app->db()->update('video_analysis', $record, 'video_id = ?', [$videoId]);
            }

            $this->app->db()->delete('video_topics', 'video_id = ?', [$videoId]);

            foreach ($topics as $topic => $weight) {
                $this->app->db()->insert('video_topics', [
                    'video_id' => $videoId,
                    'topic' => $topic,
                    'weight' => $weight,
                    'source' => 'content',
                ]);
            }
        });

        $fresh = $this->app->db()->fetch('SELECT * FROM video_analysis WHERE video_id = ? LIMIT 1', [$videoId]);

        return $fresh === null ? null : $this->payload($fresh, $video);
    }

    public function get(string $videoId, ?string $viewerId): array
    {
        $video = $this->app->videos()->findAccessible($videoId, $viewerId);
        $row = $this->app->db()->fetch('SELECT * FROM video_analysis WHERE video_id = ? LIMIT 1', [$videoId]);

        if ($row === null) {
            if (!$this->enabled()) {
                throw ApiException::notFound('Video analysis is not available.');
            }

            $analyzed = $this->analyze($videoId);

            if ($analyzed === null) {
                throw ApiException::notFound('Video analysis is not available.');
            }

            return $analyzed;
        }

        return $this->payload($row, $video);
    }

    public function payload(array $row, ?array $video = null): array
    {
        $topics = json_decode((string) ($row['topics'] ?? '[]'), true);

        return [
            'video_id' => $row['video_id'],
            'status' => $row['status'],
            'engine' => $row['engine'],
            'format' => $video !== null ? $this->app->platform()->formatOf((int) $video['duration']) : null,
            'duration' => $video !== null ? (int) $video['duration'] : null,
            'has_audio' => (bool) $row['has_audio'],
            'loudness_db' => $row['loudness'] === null ? null : round((float) $row['loudness'], 2),
            'peak_db' => $row['peak'] === null ? null : round((float) $row['peak'], 2),
            'silence_ratio' => round((float) $row['silence_ratio'], 4),
            'scene_count' => (int) $row['scene_count'],
            'cut_rate' => round((float) $row['cut_rate'], 4),
            'motion' => round((float) $row['motion'], 4),
            'visual' => [
                'brightness' => round((float) $row['brightness'], 4),
                'saturation' => round((float) $row['saturation'], 4),
                'contrast' => round((float) $row['contrast'], 4),
                'colorfulness' => round((float) $row['colorfulness'], 4),
                'dominant_color' => $row['dominant_color'],
                'palette' => json_decode((string) ($row['palette'] ?? '[]'), true),
                'perceptual_hash' => $row['perceptual_hash'],
            ],
            'topics' => is_array($topics) ? $topics : [],
            'features' => json_decode((string) ($row['features'] ?? '{}'), true),
            'analyzed_at' => $row['analyzed_at'],
        ];
    }

    public function topicsFor(string $videoId): array
    {
        $rows = $this->app->db()->fetchAll('SELECT topic, weight FROM video_topics WHERE video_id = ? ORDER BY weight DESC', [$videoId]);
        $topics = [];

        foreach ($rows as $row) {
            $topics[$row['topic']] = (float) $row['weight'];
        }

        return $topics;
    }

    public function recordSignal(string $userId, ?string $videoId, string $signal, ?float $weight = null): void
    {
        $weights = (array) $this->app->config()->get('analysis.signal_weights', []);
        $base = $weight ?? (float) ($weights[$signal] ?? 0.0);

        if ($base === 0.0) {
            return;
        }

        $this->app->db()->insert('interest_events', [
            'id' => Str::id(),
            'user_id' => $userId,
            'video_id' => $videoId,
            'signal' => $signal,
            'weight' => $base,
            'created_at' => Str::now(),
        ]);

        if ($videoId === null) {
            return;
        }

        $topics = $this->topicsFor($videoId);

        if ($topics === []) {
            return;
        }

        $now = Str::now();

        foreach ($topics as $topic => $topicWeight) {
            $delta = $base * $topicWeight;
            $existing = $this->app->db()->fetch(
                "SELECT score FROM user_interests WHERE user_id = ? AND topic = ? AND source = 'behavior' LIMIT 1",
                [$userId, $topic]
            );

            if ($existing === null) {
                if ($delta <= 0) {
                    continue;
                }

                $this->app->db()->insert('user_interests', [
                    'user_id' => $userId,
                    'topic' => $topic,
                    'score' => $delta,
                    'source' => 'behavior',
                    'updated_at' => $now,
                ]);
            } else {
                $this->app->db()->update(
                    'user_interests',
                    ['score' => max(0.0, (float) $existing['score'] + $delta), 'updated_at' => $now],
                    "user_id = ? AND topic = ? AND source = 'behavior'",
                    [$userId, $topic]
                );
            }
        }
    }

    public function recordAuthorSignal(string $userId, string $authorId, float $weight): void
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT id FROM videos WHERE user_id = ? AND status = ? ORDER BY published_at DESC LIMIT 10',
            [$authorId, 'published']
        );

        foreach ($rows as $row) {
            $topics = $this->topicsFor($row['id']);

            foreach ($topics as $topic => $topicWeight) {
                $delta = $weight * $topicWeight;

                if ($delta <= 0) {
                    continue;
                }

                $existing = $this->app->db()->fetch(
                    "SELECT score FROM user_interests WHERE user_id = ? AND topic = ? AND source = 'behavior' LIMIT 1",
                    [$userId, $topic]
                );

                if ($existing === null) {
                    $this->app->db()->insert('user_interests', [
                        'user_id' => $userId,
                        'topic' => $topic,
                        'score' => $delta,
                        'source' => 'behavior',
                        'updated_at' => Str::now(),
                    ]);
                } else {
                    $this->app->db()->update(
                        'user_interests',
                        ['score' => (float) $existing['score'] + $delta, 'updated_at' => Str::now()],
                        "user_id = ? AND topic = ? AND source = 'behavior'",
                        [$userId, $topic]
                    );
                }
            }
        }
    }

    public function setExplicit(string $userId, array $topics): array
    {
        $normalized = [];

        foreach ($topics as $topic) {
            if (!is_scalar($topic)) {
                continue;
            }

            $value = $this->normalizeTopic((string) $topic);

            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $normalized = array_slice(array_keys($normalized), 0, 20);
        $now = Str::now();
        $this->app->db()->transaction(function () use ($userId, $normalized, $now): void {
            $this->app->db()->delete('user_interests', "user_id = ? AND source = 'explicit'", [$userId]);

            foreach ($normalized as $topic) {
                $this->app->db()->insert('user_interests', [
                    'user_id' => $userId,
                    'topic' => $topic,
                    'score' => 1.0,
                    'source' => 'explicit',
                    'updated_at' => $now,
                ]);
            }
        });

        return $this->interests($userId);
    }

    public function clearExplicit(string $userId): void
    {
        $this->app->db()->delete('user_interests', "user_id = ? AND source = 'explicit'", [$userId]);
    }

    public function interests(string $userId, int $limit = 30): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT topic, score, source, updated_at FROM user_interests WHERE user_id = ? ORDER BY score DESC',
            [$userId]
        );
        $halfLife = max(1, (int) $this->app->config()->get('analysis.interest_half_life_days', 45));
        $now = time();
        $merged = [];

        foreach ($rows as $row) {
            $ageDays = max(0, ($now - Str::timestamp($row['updated_at'])) / 86400);
            $decay = exp(-$ageDays / $halfLife);
            $score = $row['source'] === 'explicit' ? (float) $row['score'] : (float) $row['score'] * $decay;

            if ($score < 0.001) {
                continue;
            }

            if (!isset($merged[$row['topic']])) {
                $merged[$row['topic']] = ['topic' => $row['topic'], 'score' => 0.0, 'sources' => []];
            }

            $merged[$row['topic']]['score'] += $score;
            $merged[$row['topic']]['sources'][] = $row['source'];
        }

        usort($merged, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $items = array_slice(array_values($merged), 0, max(1, $limit));

        return array_map(fn (array $item): array => [
            'topic' => $item['topic'],
            'score' => round($item['score'], 4),
            'sources' => array_values(array_unique($item['sources'])),
        ], $items);
    }

    public function vector(string $userId): array
    {
        $items = $this->interests($userId, 50);

        if ($items === []) {
            return [];
        }

        $max = max(array_column($items, 'score')) ?: 1.0;
        $vector = [];

        foreach ($items as $item) {
            $vector[$item['topic']] = min(1.0, $item['score'] / $max);
        }

        return $vector;
    }

    public function recordWatch(array $user, string $videoId, int $position, int $duration, ?string $sessionId = null, ?string $event = null, bool $replayed = false): array
    {
        $video = $this->app->videos()->findAccessible($videoId, $user['id']);
        $duration = $duration > 0 ? $duration : (int) $video['duration'];
        $position = max(0, min($position, max(0, $duration)));
        $ratio = $duration > 0 ? $position / $duration : 0.0;
        $completionRatio = (float) $this->app->config()->get('feed.completion_ratio', 0.9);
        $skipRatio = (float) $this->app->config()->get('feed.skip_ratio', 0.25);
        $event = $event === null ? null : strtolower(trim($event));
        $event = in_array($event, ['start', 'progress', 'complete', 'skip', 'replay'], true) ? $event : null;
        $completed = $event === 'complete' || $ratio >= $completionRatio;
        $skipped = $event === 'skip' || (!$completed && $ratio < $skipRatio && $position <= min(3, max(1, $duration)));
        $replayed = $replayed || $event === 'replay';
        $eventType = $replayed ? 'replay' : ($completed ? 'complete' : ($skipped ? 'skip' : ($event ?? 'progress')));
        $sessionId = $sessionId === null ? null : mb_substr(trim($sessionId), 0, 64, 'UTF-8');
        $sessionId = $sessionId === '' ? null : $sessionId;
        $now = Str::now();
        $this->app->db()->insert('watch_events', [
            'id' => Str::id(),
            'video_id' => $videoId,
            'user_id' => $user['id'],
            'position_seconds' => $position,
            'duration_seconds' => $duration,
            'completed' => $completed ? 1 : 0,
            'created_at' => $now,
        ]);
        $this->app->db()->insert('watch_signals', [
            'id' => Str::id(),
            'video_id' => $videoId,
            'user_id' => $user['id'],
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'position_seconds' => $position,
            'duration_seconds' => $duration,
            'watch_ratio' => round($ratio, 4),
            'completed' => $completed ? 1 : 0,
            'skipped' => $skipped ? 1 : 0,
            'replayed' => $replayed ? 1 : 0,
            'created_at' => $now,
        ]);
        $this->recordSignal($user['id'], $videoId, 'watch');

        if ($completed) {
            $this->recordSignal($user['id'], $videoId, 'complete');
        }

        if ($skipped) {
            $this->recordSignal($user['id'], $videoId, 'skip');
        }

        if ($replayed) {
            $this->recordSignal($user['id'], $videoId, 'replay');
        }

        $this->app->recommendations()->recordPlayback($videoId, true, $completed, $skipped, $replayed, $position);

        return [
            'video_id' => $videoId,
            'session_id' => $sessionId,
            'event' => $eventType,
            'position_seconds' => $position,
            'duration_seconds' => $duration,
            'watch_ratio' => round($ratio, 4),
            'completed' => $completed,
            'skipped' => $skipped,
            'replayed' => $replayed,
        ];
    }

    public function feedback(array $user, string $videoId, string $action): array
    {
        $video = $this->app->videos()->findAccessible($videoId, $user['id']);
        $existing = $this->app->db()->fetch('SELECT action FROM video_feedback WHERE user_id = ? AND video_id = ?', [$user['id'], $videoId]);

        if ($existing === null) {
            $this->app->db()->insert('video_feedback', [
                'user_id' => $user['id'],
                'video_id' => $videoId,
                'action' => $action,
                'created_at' => Str::now(),
            ]);
            $this->recordSignal($user['id'], $videoId, 'not_interested');
        } else {
            $this->app->db()->update('video_feedback', ['action' => $action, 'created_at' => Str::now()], 'user_id = ? AND video_id = ?', [$user['id'], $videoId]);
        }

        return ['video_id' => $videoId, 'action' => $action, 'hidden' => true, 'author_id' => $video['user_id']];
    }

    public function clearFeedback(array $user, string $videoId): void
    {
        $this->app->db()->delete('video_feedback', 'user_id = ? AND video_id = ?', [$user['id'], $videoId]);
    }

    public function feedbackIds(string $userId): array
    {
        $rows = $this->app->db()->fetchAll('SELECT video_id FROM video_feedback WHERE user_id = ?', [$userId]);

        return array_fill_keys(array_column($rows, 'video_id'), true);
    }

    public function seenIds(string $userId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT video_id, MAX(created_at) AS seen_at FROM watch_events WHERE user_id = ? GROUP BY video_id ORDER BY seen_at DESC LIMIT 500',
            [$userId]
        );

        return array_fill_keys(array_column($rows, 'video_id'), true);
    }

    private function buildTopics(array $video, array $hashtags, array $visual, ?array $audio, int $duration, int $sceneCount, float $cutRate): array
    {
        $scores = [];

        foreach ($hashtags as $tag) {
            $topic = $this->normalizeTopic((string) $tag);

            if ($topic !== '') {
                $scores[$topic] = max($scores[$topic] ?? 0, 1.3);
            }
        }

        $text = (string) $video['title'] . ' ' . (string) ($video['description'] ?? '');

        if (preg_match_all('/[\p{L}\p{N}_]{3,}/u', mb_strtolower($text, 'UTF-8'), $matches)) {
            $counts = [];

            foreach ($matches[0] as $word) {
                if (in_array($word, self::STOPWORDS, true) || mb_strlen($word, 'UTF-8') < 3) {
                    continue;
                }

                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }

            arsort($counts);

            foreach (array_slice($counts, 0, 8, true) as $word => $count) {
                $topic = $this->normalizeTopic($word);

                if ($topic !== '') {
                    $scores[$topic] = max($scores[$topic] ?? 0, 0.7 + min(0.4, $count * 0.1));
                }
            }
        }

        $add = function (string $topic, float $weight) use (&$scores): void {
            $scores[$topic] = max($scores[$topic] ?? 0, $weight);
        };
        $add($duration >= $this->app->platform()->longMin() ? 'long_form' : 'short_form', 1.5);
        $height = (int) ($visual['height'] ?? 0);
        $width = (int) ($visual['width'] ?? 0);

        if ($height > 0 && $width > 0) {
            if ($height > $width * 1.1) {
                $add('vertical_video', 0.8);
            } elseif ($width > $height * 1.1) {
                $add('horizontal_video', 0.6);
            } else {
                $add('square_video', 0.6);
            }
        }

        $brightness = (float) ($visual['brightness'] ?? 0.5);
        $saturation = (float) ($visual['saturation'] ?? 0.3);
        $motion = (float) ($visual['motion'] ?? 0);
        $add($brightness > 0.62 ? 'bright' : ($brightness < 0.38 ? 'dark' : 'balanced_light'), 0.9);
        $add($saturation > 0.48 ? 'colorful' : ($saturation < 0.14 ? 'monochrome' : 'natural_color'), 0.9);
        $add($motion > 0.18 ? 'high_motion' : ($motion < 0.045 ? 'static' : 'moderate_motion'), 1.0);
        $add($cutRate > 0.25 ? 'fast_cuts' : ($sceneCount <= 1 ? 'continuous_shot' : 'moderate_cuts'), 1.0);

        if ($audio === null) {
            $add('silent_video', 1.0);
        } else {
            $add('with_audio', 1.0);
            $loudness = $audio['loudness'] ?? null;

            if ($loudness !== null) {
                $add((float) $loudness > -18 ? 'loud_audio' : ((float) $loudness < -35 ? 'quiet_audio' : 'balanced_audio'), 0.9);
            }

            if ((float) ($audio['silence_ratio'] ?? 0) > 0.5) {
                $add('mostly_silent', 0.9);
            }
        }

        $dom = (string) ($visual['dominant_color'] ?? '');
        $dom = strtolower($dom);
        $add(in_array($dom, ['red', 'orange', 'yellow', 'pink'], true) ? 'warm_palette' : 'cool_palette', 0.5);

        $max = max($scores ?: [1.0]);
        $topics = [];

        foreach ($scores as $topic => $weight) {
            $topics[$topic] = round(min(1.0, $weight / max(0.001, $max)), 4);
        }

        arsort($topics);

        return array_slice($topics, 0, max(1, (int) $this->app->config()->get('analysis.max_topics', 14)), true);
    }

    private function visualFeatures(array $frames): array
    {
        $defaults = [
            'brightness' => 0.5,
            'saturation' => 0.3,
            'contrast' => 0.2,
            'colorfulness' => 0.0,
            'motion' => 0.0,
            'dominant_color' => null,
            'palette' => [],
            'perceptual_hash' => null,
            'width' => 0,
            'height' => 0,
        ];

        if ($frames === [] || !function_exists('imagecreatefromjpeg')) {
            return $defaults;
        }

        $brightness = [];
        $saturation = [];
        $luma = [];
        $colorfulness = [];
        $buckets = [];
        $width = 0;
        $height = 0;
        $hash = null;

        foreach ($frames as $index => $frame) {
            $image = @imagecreatefromjpeg($frame);

            if ($image === false) {
                continue;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $stepX = max(1, (int) floor($width / 90));
            $stepY = max(1, (int) floor($height / 90));
            $sum = 0.0;
            $sumSq = 0.0;
            $sat = 0.0;
            $samples = 0;
            $rg = 0.0;
            $yb = 0.0;
            $rgSq = 0.0;
            $ybSq = 0.0;
            $rgYb = 0.0;
            $frameLuma = [];

            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
                    $max = max($r, $g, $b);
                    $min = min($r, $g, $b);
                    $sum += $luminance;
                    $sumSq += $luminance * $luminance;
                    $sat += $max === 0 ? 0 : ($max - $min) / $max;
                    $samples++;
                    $frameLuma[] = $luminance;
                    $dRg = (float) ($r - $g);
                    $dYb = (float) (0.5 * ($r + $g) - $b);
                    $rg += $dRg;
                    $yb += $dYb;
                    $rgSq += $dRg * $dRg;
                    $ybSq += $dYb * $dYb;
                    $rgYb += $dRg * $dYb;

                    if ($max - $min < 22) {
                        $key = $luminance < 0.2 ? 'black' : ($luminance > 0.82 ? 'white' : 'gray');
                        $buckets[$key] = ($buckets[$key] ?? 0) + 1;
                    } else {
                        $hue = $this->hue($r, $g, $b);
                        $name = $hue < 20 || $hue >= 345 ? 'red' : ($hue < 45 ? 'orange' : ($hue < 70 ? 'yellow' : ($hue < 165 ? 'green' : ($hue < 200 ? 'cyan' : ($hue < 260 ? 'blue' : ($hue < 300 ? 'purple' : 'pink'))))));
                        $buckets[$name] = ($buckets[$name] ?? 0) + 1;
                    }
                }
            }

            if ($samples === 0) {
                imagedestroy($image);
                continue;
            }

            $mean = $sum / $samples;
            $brightness[] = $mean;
            $saturation[] = $sat / $samples;
            $contrast = sqrt(max(0, $sumSq / $samples - $mean * $mean));
            $luma[] = ['mean' => $mean, 'contrast' => $contrast, 'pixels' => $frameLuma];
            $meanRg = $rg / $samples;
            $meanYb = $yb / $samples;
            $stdRg = sqrt(max(0, $rgSq / $samples - $meanRg * $meanRg));
            $stdYb = sqrt(max(0, $ybSq / $samples - $meanYb * $meanYb));
            $colorfulness[] = sqrt($stdRg * $stdRg + $stdYb * $stdYb) + 0.3 * sqrt($meanRg * $meanRg + $meanYb * $meanYb);

            if ($index === 0 && function_exists('imagecreatetruecolor')) {
                $hash = $this->differenceHash($image);
            }

            imagedestroy($image);
        }

        if ($brightness === []) {
            return $defaults;
        }

        $motion = 0.0;
        $pairs = 0;

        for ($index = 1; $index < count($luma); $index++) {
            $first = $luma[$index - 1]['pixels'];
            $second = $luma[$index]['pixels'];
            $count = min(count($first), count($second));

            if ($count === 0) {
                continue;
            }

            $diff = 0.0;

            for ($i = 0; $i < $count; $i++) {
                $diff += abs($first[$i] - $second[$i]);
            }

            $motion += ($diff / $count);
            $pairs++;
        }

        arsort($buckets);
        $totalBuckets = max(1, array_sum($buckets));
        $palette = [];
        $colors = [
            'red' => '#ef4444', 'orange' => '#f97316', 'yellow' => '#eab308', 'green' => '#22c55e',
            'cyan' => '#06b6d4', 'blue' => '#3b82f6', 'purple' => '#8b5cf6', 'pink' => '#ec4899',
            'black' => '#111111', 'gray' => '#6b7280', 'white' => '#f8fafc',
        ];
        $names = [
            'red' => 'red', 'orange' => 'orange', 'yellow' => 'yellow', 'green' => 'green', 'cyan' => 'cyan',
            'blue' => 'blue', 'purple' => 'purple', 'pink' => 'pink', 'black' => 'black', 'gray' => 'gray', 'white' => 'white',
        ];

        foreach (array_slice($buckets, 0, 5, true) as $name => $count) {
            $palette[] = [
                'name' => $names[$name] ?? $name,
                'hex' => $colors[$name] ?? '#6b7280',
                'ratio' => round($count / $totalBuckets, 4),
            ];
        }

        return [
            'brightness' => array_sum($brightness) / count($brightness),
            'saturation' => array_sum($saturation) / count($saturation),
            'contrast' => array_sum(array_column($luma, 'contrast')) / count($luma),
            'colorfulness' => array_sum($colorfulness) / count($colorfulness),
            'motion' => $pairs > 0 ? $motion / $pairs : 0.0,
            'dominant_color' => $palette[0]['name'] ?? null,
            'palette' => $palette,
            'perceptual_hash' => $hash,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function differenceHash(\GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 9, 8, $width, $height);
        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $first = imagecolorat($sample, $x, $y);
                $second = imagecolorat($sample, $x + 1, $y);
                $firstLuma = (($first >> 16) & 0xFF) * 0.299 + (($first >> 8) & 0xFF) * 0.587 + ($first & 0xFF) * 0.114;
                $secondLuma = (($second >> 16) & 0xFF) * 0.299 + (($second >> 8) & 0xFF) * 0.587 + ($second & 0xFF) * 0.114;
                $bits .= $firstLuma > $secondLuma ? '1' : '0';
            }
        }

        imagedestroy($sample);

        return dechex((int) bindec(substr($bits, 0, 32))) . dechex((int) bindec(substr($bits, 32, 32)));
    }

    private function hue(int $r, int $g, int $b): float
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        if ($delta == 0.0) {
            return 0.0;
        }

        if ($max === $r) {
            $hue = fmod((($g - $b) / $delta), 6);
        } elseif ($max === $g) {
            $hue = (($b - $r) / $delta) + 2;
        } else {
            $hue = (($r - $g) / $delta) + 4;
        }

        $hue *= 60;

        return $hue < 0 ? $hue + 360 : $hue;
    }

    private function normalizeTopic(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = ltrim($value, '#');
        $value = preg_replace('/[^\p{L}\p{N}_]+/u', '_', $value) ?? '';
        $value = trim($value, '_');

        return mb_substr($value, 0, 60, 'UTF-8');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
