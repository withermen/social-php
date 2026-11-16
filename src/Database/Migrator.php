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
        ];
    }
}
