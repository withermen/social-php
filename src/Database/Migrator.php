<?php

namespace Social\Database;

final class Migrator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function run(): void
    {
        foreach ($this->tables() as $table => $definition) {
            $this->createTable($table, $definition);
        }
    }

    private function createTable(string $table, array $definition): void
    {
        $columns = [];

        foreach ($definition['columns'] as $name => $type) {
            $columns[] = '`' . $name . '` ' . $type;
        }

        foreach ($definition['unique'] ?? [] as $unique) {
            $columns[] = 'UNIQUE (' . $this->identifiers($unique) . ')';
        }

        if ($definition['primary'] ?? null) {
            $columns[] = 'PRIMARY KEY (' . $this->identifiers($definition['primary']) . ')';
        }

        if ($this->db->driver() === 'mysql') {
            foreach ($definition['indexes'] ?? [] as $name => $index) {
                $columns[] = 'INDEX `' . $name . '` (' . $this->identifiers($index) . ')';
            }
        }

        $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $columns) . ')';

        if ($this->db->driver() === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        $this->db->pdo()->exec($sql);

        if ($this->db->driver() === 'sqlite') {
            foreach ($definition['indexes'] ?? [] as $name => $index) {
                $this->db->pdo()->exec('CREATE INDEX IF NOT EXISTS `' . $name . '` ON `' . $table . '` (' . $this->identifiers($index) . ')');
            }
        }
    }

    private function identifiers(array $columns): string
    {
        return implode(', ', array_map(fn (string $column): string => '`' . $column . '`', $columns));
    }

    private function tables(): array
    {
        return [
            'users' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'username' => 'VARCHAR(64) NOT NULL',
                    'email' => 'VARCHAR(190) NOT NULL',
                    'password_hash' => 'VARCHAR(255) NOT NULL',
                    'display_name' => 'VARCHAR(100) NOT NULL',
                    'bio' => 'TEXT NULL',
                    'website' => 'VARCHAR(255) NULL',
                    'location' => 'VARCHAR(100) NULL',
                    'avatar_path' => 'VARCHAR(255) NULL',
                    'role' => "VARCHAR(20) NOT NULL DEFAULT 'user'",
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'verified' => 'TINYINT NOT NULL DEFAULT 0',
                    'email_verified_at' => 'VARCHAR(32) NULL',
                    'last_seen_at' => 'VARCHAR(32) NULL',
                    'follower_count' => 'INT NOT NULL DEFAULT 0',
                    'following_count' => 'INT NOT NULL DEFAULT 0',
                    'video_count' => 'INT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['username'], ['email']],
                'indexes' => [
                    'users_status_idx' => ['status'],
                    'users_role_idx' => ['role'],
                ],
            ],
            'refresh_tokens' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'token_hash' => 'VARCHAR(64) NOT NULL',
                    'expires_at' => 'VARCHAR(32) NOT NULL',
                    'revoked_at' => 'VARCHAR(32) NULL',
                    'user_agent' => 'VARCHAR(500) NULL',
                    'ip' => 'VARCHAR(64) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['token_hash']],
                'indexes' => [
                    'refresh_tokens_user_idx' => ['user_id'],
                    'refresh_tokens_expiry_idx' => ['expires_at'],
                ],
            ],
            'verification_tokens' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'purpose' => 'VARCHAR(20) NOT NULL',
                    'token_hash' => 'VARCHAR(64) NOT NULL',
                    'expires_at' => 'VARCHAR(32) NOT NULL',
                    'used_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['token_hash']],
                'indexes' => [
                    'verification_tokens_user_idx' => ['user_id'],
                    'verification_tokens_expiry_idx' => ['expires_at'],
                ],
            ],
            'follows' => [
                'columns' => [
                    'follower_id' => 'VARCHAR(32) NOT NULL',
                    'following_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['follower_id', 'following_id'],
                'indexes' => [
                    'follows_following_idx' => ['following_id'],
                    'follows_created_idx' => ['created_at'],
                ],
            ],
            'blocks' => [
                'columns' => [
                    'blocker_id' => 'VARCHAR(32) NOT NULL',
                    'blocked_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['blocker_id', 'blocked_id'],
                'indexes' => [
                    'blocks_blocked_idx' => ['blocked_id'],
                ],
            ],
            'mutes' => [
                'columns' => [
                    'muter_id' => 'VARCHAR(32) NOT NULL',
                    'muted_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['muter_id', 'muted_id'],
                'indexes' => [
                    'mutes_muted_idx' => ['muted_id'],
                ],
            ],
            'videos' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'title' => 'VARCHAR(150) NOT NULL',
                    'description' => 'TEXT NULL',
                    'visibility' => "VARCHAR(20) NOT NULL DEFAULT 'public'",
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'published'",
                    'storage_path' => 'VARCHAR(255) NOT NULL',
                    'thumbnail_path' => 'VARCHAR(255) NULL',
                    'mime' => 'VARCHAR(100) NOT NULL',
                    'size' => 'INT NOT NULL DEFAULT 0',
                    'duration' => 'INT NOT NULL DEFAULT 0',
                    'width' => 'INT NOT NULL DEFAULT 0',
                    'height' => 'INT NOT NULL DEFAULT 0',
                    'like_count' => 'INT NOT NULL DEFAULT 0',
                    'comment_count' => 'INT NOT NULL DEFAULT 0',
                    'view_count' => 'INT NOT NULL DEFAULT 0',
                    'share_count' => 'INT NOT NULL DEFAULT 0',
                    'save_count' => 'INT NOT NULL DEFAULT 0',
                    'published_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'videos_user_idx' => ['user_id'],
                    'videos_feed_idx' => ['status', 'visibility', 'published_at'],
                    'videos_created_idx' => ['created_at'],
                ],
            ],
            'video_views' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NULL',
                    'ip_hash' => 'VARCHAR(64) NULL',
                    'viewed_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'video_views_video_idx' => ['video_id', 'viewed_at'],
                    'video_views_user_idx' => ['user_id'],
                ],
            ],
            'likes' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'target_type' => 'VARCHAR(20) NOT NULL',
                    'target_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['user_id', 'target_type', 'target_id']],
                'indexes' => [
                    'likes_target_idx' => ['target_type', 'target_id'],
                    'likes_user_idx' => ['user_id'],
                ],
            ],
            'comments' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'parent_id' => 'VARCHAR(32) NULL',
                    'reply_to_id' => 'VARCHAR(32) NULL',
                    'body' => 'TEXT NOT NULL',
                    'like_count' => 'INT NOT NULL DEFAULT 0',
                    'reply_count' => 'INT NOT NULL DEFAULT 0',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'published'",
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'comments_video_idx' => ['video_id', 'status', 'created_at'],
                    'comments_parent_idx' => ['parent_id'],
                    'comments_user_idx' => ['user_id'],
                ],
            ],
            'saves' => [
                'columns' => [
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['user_id', 'video_id'],
                'indexes' => [
                    'saves_video_idx' => ['video_id'],
                ],
            ],
            'shares' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NULL',
                    'platform' => 'VARCHAR(30) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'shares_video_idx' => ['video_id'],
                ],
            ],
            'hashtags' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'tag' => 'VARCHAR(50) NOT NULL',
                    'video_count' => 'INT NOT NULL DEFAULT 0',
                    'view_count' => 'INT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['tag']],
                'indexes' => [
                    'hashtags_count_idx' => ['video_count'],
                ],
            ],
            'video_hashtags' => [
                'columns' => [
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'hashtag_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['video_id', 'hashtag_id'],
                'indexes' => [
                    'video_hashtags_tag_idx' => ['hashtag_id'],
                ],
            ],
            'mentions' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'mentioned_user_id' => 'VARCHAR(32) NOT NULL',
                    'target_type' => 'VARCHAR(20) NOT NULL',
                    'target_id' => 'VARCHAR(32) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'mentions_user_idx' => ['mentioned_user_id'],
                    'mentions_target_idx' => ['target_type', 'target_id'],
                ],
            ],
            'notifications' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'actor_id' => 'VARCHAR(32) NULL',
                    'type' => 'VARCHAR(40) NOT NULL',
                    'target_type' => 'VARCHAR(20) NULL',
                    'target_id' => 'VARCHAR(32) NULL',
                    'data' => 'TEXT NULL',
                    'read_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'notifications_user_idx' => ['user_id', 'created_at'],
                    'notifications_unread_idx' => ['user_id', 'read_at'],
                ],
            ],
            'conversations' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'type' => "VARCHAR(20) NOT NULL DEFAULT 'direct'",
                    'last_message_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'conversations_last_idx' => ['last_message_at'],
                ],
            ],
            'conversation_members' => [
                'columns' => [
                    'conversation_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'last_read_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['conversation_id', 'user_id'],
                'indexes' => [
                    'conversation_members_user_idx' => ['user_id'],
                ],
            ],
            'messages' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'conversation_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'body' => 'TEXT NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'messages_conversation_idx' => ['conversation_id', 'created_at'],
                ],
            ],
            'reports' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'reporter_id' => 'VARCHAR(32) NOT NULL',
                    'target_type' => 'VARCHAR(20) NOT NULL',
                    'target_id' => 'VARCHAR(32) NOT NULL',
                    'reason' => 'VARCHAR(40) NOT NULL',
                    'details' => 'TEXT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'open'",
                    'resolved_by' => 'VARCHAR(32) NULL',
                    'resolved_at' => 'VARCHAR(32) NULL',
                    'resolution_note' => 'TEXT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'reports_status_idx' => ['status', 'created_at'],
                    'reports_target_idx' => ['target_type', 'target_id'],
                ],
            ],
            'upload_sessions' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'filename' => 'VARCHAR(255) NOT NULL',
                    'mime' => 'VARCHAR(100) NOT NULL',
                    'size' => 'INT NOT NULL',
                    'received' => 'INT NOT NULL DEFAULT 0',
                    'title' => 'VARCHAR(150) NOT NULL',
                    'description' => 'TEXT NULL',
                    'visibility' => "VARCHAR(20) NOT NULL DEFAULT 'public'",
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'uploading'",
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'upload_sessions_user_idx' => ['user_id', 'status'],
                ],
            ],
            'rate_limits' => [
                'columns' => [
                    'bucket' => 'VARCHAR(190) NOT NULL',
                    'window_start' => 'INT NOT NULL',
                    'hits' => 'INT NOT NULL DEFAULT 0',
                ],
                'primary' => ['bucket', 'window_start'],
                'indexes' => [
                    'rate_limits_window_idx' => ['window_start'],
                ],
            ],
            'video_analysis' => [
                'columns' => [
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
                    'engine' => 'VARCHAR(40) NOT NULL',
                    'has_audio' => 'TINYINT NOT NULL DEFAULT 0',
                    'fps' => 'REAL NOT NULL DEFAULT 0',
                    'bitrate' => 'INT NOT NULL DEFAULT 0',
                    'loudness' => 'REAL NULL',
                    'peak' => 'REAL NULL',
                    'silence_ratio' => 'REAL NOT NULL DEFAULT 0',
                    'scene_count' => 'INT NOT NULL DEFAULT 0',
                    'cut_rate' => 'REAL NOT NULL DEFAULT 0',
                    'motion' => 'REAL NOT NULL DEFAULT 0',
                    'brightness' => 'REAL NOT NULL DEFAULT 0',
                    'saturation' => 'REAL NOT NULL DEFAULT 0',
                    'contrast' => 'REAL NOT NULL DEFAULT 0',
                    'colorfulness' => 'REAL NOT NULL DEFAULT 0',
                    'dominant_color' => 'VARCHAR(24) NULL',
                    'palette' => 'TEXT NULL',
                    'perceptual_hash' => 'VARCHAR(32) NULL',
                    'topics' => 'TEXT NULL',
                    'features' => 'TEXT NULL',
                    'analyzed_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['video_id'],
                'indexes' => [
                    'video_analysis_status_idx' => ['status'],
                ],
            ],
            'video_topics' => [
                'columns' => [
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'topic' => 'VARCHAR(80) NOT NULL',
                    'weight' => 'REAL NOT NULL DEFAULT 0',
                    'source' => "VARCHAR(20) NOT NULL DEFAULT 'content'",
                ],
                'primary' => ['video_id', 'topic'],
                'indexes' => [
                    'video_topics_topic_idx' => ['topic'],
                ],
            ],
            'interest_events' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NULL',
                    'signal' => 'VARCHAR(30) NOT NULL',
                    'weight' => 'REAL NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'interest_events_user_idx' => ['user_id', 'created_at'],
                    'interest_events_video_idx' => ['video_id'],
                ],
            ],
            'user_interests' => [
                'columns' => [
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'topic' => 'VARCHAR(80) NOT NULL',
                    'score' => 'REAL NOT NULL DEFAULT 0',
                    'source' => "VARCHAR(20) NOT NULL DEFAULT 'behavior'",
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['user_id', 'topic', 'source'],
                'indexes' => [
                    'user_interests_topic_idx' => ['topic'],
                    'user_interests_score_idx' => ['user_id', 'score'],
                ],
            ],
            'watch_events' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'position_seconds' => 'INT NOT NULL DEFAULT 0',
                    'duration_seconds' => 'INT NOT NULL DEFAULT 0',
                    'completed' => 'TINYINT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'watch_events_user_idx' => ['user_id', 'created_at'],
                    'watch_events_video_idx' => ['video_id'],
                ],
            ],
            'video_feedback' => [
                'columns' => [
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'action' => 'VARCHAR(30) NOT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['user_id', 'video_id'],
                'indexes' => [
                    'video_feedback_action_idx' => ['action'],
                ],
            ],
            'watch_signals' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'session_id' => 'VARCHAR(64) NULL',
                    'event_type' => 'VARCHAR(20) NOT NULL',
                    'position_seconds' => 'INT NOT NULL DEFAULT 0',
                    'duration_seconds' => 'INT NOT NULL DEFAULT 0',
                    'watch_ratio' => 'REAL NOT NULL DEFAULT 0',
                    'completed' => 'TINYINT NOT NULL DEFAULT 0',
                    'skipped' => 'TINYINT NOT NULL DEFAULT 0',
                    'replayed' => 'TINYINT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'watch_signals_user_idx' => ['user_id', 'created_at'],
                    'watch_signals_video_idx' => ['video_id', 'created_at'],
                    'watch_signals_session_idx' => ['session_id', 'created_at'],
                ],
            ],
            'feed_impressions' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NULL',
                    'session_id' => 'VARCHAR(64) NOT NULL',
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'source' => 'VARCHAR(20) NOT NULL',
                    'position' => 'INT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'feed_impressions_user_idx' => ['user_id', 'created_at'],
                    'feed_impressions_session_idx' => ['session_id', 'created_at'],
                    'feed_impressions_video_idx' => ['video_id', 'created_at'],
                ],
            ],
            'video_performance' => [
                'columns' => [
                    'video_id' => 'VARCHAR(32) NOT NULL',
                    'impressions' => 'INT NOT NULL DEFAULT 0',
                    'starts' => 'INT NOT NULL DEFAULT 0',
                    'completions' => 'INT NOT NULL DEFAULT 0',
                    'skips' => 'INT NOT NULL DEFAULT 0',
                    'replays' => 'INT NOT NULL DEFAULT 0',
                    'watch_seconds' => 'INT NOT NULL DEFAULT 0',
                    'likes' => 'INT NOT NULL DEFAULT 0',
                    'comments' => 'INT NOT NULL DEFAULT 0',
                    'shares' => 'INT NOT NULL DEFAULT 0',
                    'saves' => 'INT NOT NULL DEFAULT 0',
                    'follows' => 'INT NOT NULL DEFAULT 0',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['video_id'],
                'indexes' => [
                    'video_performance_retention_idx' => ['completions', 'starts'],
                    'video_performance_watch_idx' => ['watch_seconds'],
                ],
            ],
            'ad_campaigns' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'advertiser_id' => 'VARCHAR(32) NULL',
                    'name' => 'VARCHAR(120) NOT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'draft'",
                    'placement' => "VARCHAR(20) NOT NULL DEFAULT 'feed'",
                    'objective' => "VARCHAR(20) NOT NULL DEFAULT 'impressions'",
                    'budget_cents' => 'INT NOT NULL DEFAULT 0',
                    'spent_cents' => 'INT NOT NULL DEFAULT 0',
                    'bid_cents' => 'INT NOT NULL DEFAULT 0',
                    'targeting' => 'TEXT NULL',
                    'starts_at' => 'VARCHAR(32) NULL',
                    'ends_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'ad_campaigns_status_idx' => ['status', 'placement'],
                    'ad_campaigns_advertiser_idx' => ['advertiser_id'],
                ],
            ],
            'ad_creatives' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'campaign_id' => 'VARCHAR(32) NOT NULL',
                    'title' => 'VARCHAR(120) NOT NULL',
                    'body' => 'VARCHAR(500) NULL',
                    'cta_label' => 'VARCHAR(40) NOT NULL',
                    'click_url' => 'VARCHAR(500) NOT NULL',
                    'image_path' => 'VARCHAR(255) NULL',
                    'image_url' => 'VARCHAR(500) NULL',
                    'video_id' => 'VARCHAR(32) NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'weight' => 'INT NOT NULL DEFAULT 100',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'ad_creatives_campaign_idx' => ['campaign_id', 'status'],
                ],
            ],
            'ad_events' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'campaign_id' => 'VARCHAR(32) NOT NULL',
                    'creative_id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NULL',
                    'type' => 'VARCHAR(20) NOT NULL',
                    'placement' => 'VARCHAR(20) NULL',
                    'ip_hash' => 'VARCHAR(64) NULL',
                    'cost_cents' => 'INT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'ad_events_campaign_idx' => ['campaign_id', 'type', 'created_at'],
                    'ad_events_creative_idx' => ['creative_id', 'type'],
                    'ad_events_user_idx' => ['user_id', 'created_at'],
                ],
            ],
            'license_keys' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'key_hash' => 'VARCHAR(64) NOT NULL',
                    'key_preview' => 'VARCHAR(32) NOT NULL',
                    'plan' => 'VARCHAR(40) NOT NULL',
                    'holder_name' => 'VARCHAR(120) NULL',
                    'holder_email' => 'VARCHAR(190) NOT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'max_activations' => 'INT NOT NULL DEFAULT 1',
                    'activation_count' => 'INT NOT NULL DEFAULT 0',
                    'issued_at' => 'VARCHAR(32) NOT NULL',
                    'expires_at' => 'VARCHAR(32) NULL',
                    'revoked_at' => 'VARCHAR(32) NULL',
                    'metadata' => 'TEXT NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['key_hash']],
                'indexes' => [
                    'license_keys_status_idx' => ['status', 'expires_at'],
                    'license_keys_email_idx' => ['holder_email'],
                ],
            ],
            'license_activations' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'license_id' => 'VARCHAR(32) NOT NULL',
                    'installation_id' => 'VARCHAR(120) NOT NULL',
                    'domain' => 'VARCHAR(190) NULL',
                    'ip_hash' => 'VARCHAR(64) NULL',
                    'first_seen_at' => 'VARCHAR(32) NOT NULL',
                    'last_seen_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['license_id', 'installation_id']],
                'indexes' => [
                    'license_activations_installation_idx' => ['installation_id'],
                ],
            ],
            'license_orders' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'provider' => 'VARCHAR(40) NOT NULL',
                    'provider_ref' => 'VARCHAR(190) NULL',
                    'plan' => 'VARCHAR(40) NOT NULL',
                    'amount_cents' => 'INT NOT NULL DEFAULT 0',
                    'currency' => 'VARCHAR(8) NOT NULL DEFAULT \'USD\'',
                    'holder_name' => 'VARCHAR(120) NULL',
                    'email' => 'VARCHAR(190) NOT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
                    'license_id' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'license_orders_status_idx' => ['status', 'created_at'],
                    'license_orders_ref_idx' => ['provider', 'provider_ref'],
                ],
            ],
            'premium_plans' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'code' => 'VARCHAR(40) NOT NULL',
                    'name' => 'VARCHAR(80) NOT NULL',
                    'description' => 'VARCHAR(500) NULL',
                    'price_cents' => 'INT NOT NULL DEFAULT 0',
                    'currency' => 'VARCHAR(8) NOT NULL DEFAULT \'USD\'',
                    'interval' => "VARCHAR(20) NOT NULL DEFAULT 'month'",
                    'features' => 'TEXT NULL',
                    'limits' => 'TEXT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'sort_order' => 'INT NOT NULL DEFAULT 0',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['code']],
                'indexes' => [
                    'premium_plans_status_idx' => ['status', 'sort_order'],
                ],
            ],
            'subscriptions' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'user_id' => 'VARCHAR(32) NOT NULL',
                    'plan_id' => 'VARCHAR(32) NOT NULL',
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'provider' => 'VARCHAR(40) NOT NULL',
                    'provider_ref' => 'VARCHAR(190) NULL',
                    'started_at' => 'VARCHAR(32) NOT NULL',
                    'current_period_end' => 'VARCHAR(32) NULL',
                    'cancel_at_period_end' => 'TINYINT NOT NULL DEFAULT 0',
                    'canceled_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                    'updated_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'indexes' => [
                    'subscriptions_user_idx' => ['user_id', 'status'],
                    'subscriptions_period_idx' => ['status', 'current_period_end'],
                    'subscriptions_provider_idx' => ['provider', 'provider_ref'],
                ],
            ],
            'payment_events' => [
                'columns' => [
                    'id' => 'VARCHAR(32) NOT NULL',
                    'provider' => 'VARCHAR(40) NOT NULL',
                    'event_type' => 'VARCHAR(60) NOT NULL',
                    'provider_ref' => 'VARCHAR(190) NOT NULL',
                    'payload' => 'TEXT NULL',
                    'processed_at' => 'VARCHAR(32) NULL',
                    'created_at' => 'VARCHAR(32) NOT NULL',
                ],
                'primary' => ['id'],
                'unique' => [['provider', 'provider_ref']],
                'indexes' => [
                    'payment_events_type_idx' => ['event_type', 'created_at'],
                ],
            ],
        ];
    }
}
