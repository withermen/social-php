<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Support\Str;
use Social\Support\Validator;

final class MessageService
{
    public function __construct(private readonly App $app)
    {
    }

    public function start(array $user, array $data): array
    {
        $target = null;

        if (!empty($data['user_id'])) {
            $target = $this->app->users()->requireById((string) $data['user_id']);
        } elseif (!empty($data['username'])) {
            $target = $this->app->users()->byUsername((string) $data['username']);
        } else {
            throw new ValidationException(['username' => 'Provide a username or user_id.']);
        }

        if ($target['id'] === $user['id']) {
            throw ApiException::badRequest('You cannot start a conversation with yourself.');
        }

        if ($target['status'] !== 'active') {
            throw ApiException::notFound('User not found.');
        }

        if ($this->app->users()->isBlockedBetween($user['id'], $target['id'])) {
            throw ApiException::forbidden('This conversation is not available.');
        }

        $existing = $this->app->db()->fetch(
            "SELECT c.id FROM conversations c
             JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
             JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
             WHERE c.type = 'direct' LIMIT 1",
            [$user['id'], $target['id']]
        );

        if ($existing !== null) {
            return $this->show($user, $existing['id']);
        }

        $id = Str::id();
        $now = Str::now();
        $this->app->db()->transaction(function () use ($id, $user, $target, $now): void {
            $this->app->db()->insert('conversations', [
                'id' => $id,
                'type' => 'direct',
                'last_message_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->app->db()->insert('conversation_members', [
                'conversation_id' => $id,
                'user_id' => $user['id'],
                'last_read_at' => $now,
                'created_at' => $now,
            ]);
            $this->app->db()->insert('conversation_members', [
                'conversation_id' => $id,
                'user_id' => $target['id'],
                'last_read_at' => null,
                'created_at' => $now,
            ]);
        });

        return $this->show($user, $id);
    }

    public function list(array $user, int $limit = 50): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT c.* FROM conversations c JOIN conversation_members m ON m.conversation_id = c.id WHERE m.user_id = ? ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT ' . max(1, min(100, $limit)),
            [$user['id']]
        );

        return ['items' => array_map(fn (array $row): array => $this->conversationPayload($row, $user['id']), $rows)];
    }

    public function show(array $user, string $id): array
    {
        $conversation = $this->membership($user, $id);
        $payload = $this->conversationPayload($conversation, $user['id']);
        $payload['messages'] = $this->messageRows($user, $id, 1, 30)['items'];

        return $payload;
    }

    public function messages(array $user, string $id, int $page, int $perPage): array
    {
        $this->membership($user, $id);

        return $this->messageRows($user, $id, $page, $perPage);
    }

    public function send(array $user, string $id, array $data): array
    {
        $limit = $this->app->rateLimiter()->attempt('message:' . $user['id'], 60, 60);

        if (!$limit['allowed']) {
            throw ApiException::tooManyAttempts('You are sending messages too fast.', $limit['retry_after']);
        }

        $clean = Validator::validate($data, ['body' => 'required|string:1,2000']);
        $conversation = $this->membership($user, $id);
        $other = $this->otherMember($id, $user['id']);

        if ($other !== null && $this->app->users()->isBlockedBetween($user['id'], $other['user_id'])) {
            throw ApiException::forbidden('This conversation is not available.');
        }

        $messageId = Str::id();
        $now = Str::now();
        $this->app->db()->transaction(function () use ($messageId, $id, $user, $clean, $now): void {
            $this->app->db()->insert('messages', [
                'id' => $messageId,
                'conversation_id' => $id,
                'user_id' => $user['id'],
                'body' => $clean['body'],
                'created_at' => $now,
            ]);
            $this->app->db()->update('conversations', ['last_message_at' => $now, 'updated_at' => $now], 'id = ?', [$id]);
            $this->app->db()->update('conversation_members', ['last_read_at' => $now], 'conversation_id = ? AND user_id = ?', [$id, $user['id']]);
        });

        if ($other !== null) {
            $this->app->notifications()->create($other['user_id'], $user['id'], 'message', 'conversation', $id, [
                'message_id' => $messageId,
                'preview' => Str::excerpt($clean['body'], 90),
            ]);
        }

        $message = $this->app->db()->fetch('SELECT * FROM messages WHERE id = ?', [$messageId]);

        return $this->messagePayload($message, $user);
    }

    public function markRead(array $user, string $id): void
    {
        $this->membership($user, $id);
        $this->app->db()->update(
            'conversation_members',
            ['last_read_at' => Str::now()],
            'conversation_id = ? AND user_id = ?',
            [$id, $user['id']]
        );
    }

    private function messageRows(array $user, string $id, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->app->db()->value('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$id]);
        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$id]
        );
        $rows = array_reverse($rows);
        $items = array_map(fn (array $row): array => $this->messagePayload($row, $user), $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    private function messagePayload(array $row, array $viewer): array
    {
        $sender = $this->app->db()->fetch('SELECT id, username, display_name, avatar_path, verified FROM users WHERE id = ?', [$row['user_id']]);

        return [
            'id' => $row['id'],
            'conversation_id' => $row['conversation_id'],
            'body' => $row['body'],
            'sender' => $sender === null ? null : [
                'id' => $sender['id'],
                'username' => $sender['username'],
                'display_name' => $sender['display_name'],
                'avatar_url' => $sender['avatar_path'] ? $this->app->assetUrl($sender['avatar_path']) : null,
                'verified' => (bool) $sender['verified'],
            ],
            'is_mine' => $row['user_id'] === $viewer['id'],
            'created_at' => $row['created_at'],
        ];
    }

    private function conversationPayload(array $conversation, string $userId): array
    {
        $other = $this->otherMember($conversation['id'], $userId);
        $otherUser = null;
        $unread = 0;

        if ($other !== null) {
            $row = $this->app->db()->fetch('SELECT id, username, display_name, avatar_path, verified FROM users WHERE id = ?', [$other['user_id']]);
            $otherUser = $row === null ? null : [
                'id' => $row['id'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'avatar_url' => $row['avatar_path'] ? $this->app->assetUrl($row['avatar_path']) : null,
                'verified' => (bool) $row['verified'],
            ];
            $member = $this->app->db()->fetch(
                'SELECT last_read_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?',
                [$conversation['id'], $userId]
            );
            $lastRead = $member['last_read_at'] ?? null;
            $unread = (int) $this->app->db()->value(
                'SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND user_id <> ? AND (? IS NULL OR created_at > ?)',
                [$conversation['id'], $userId, $lastRead, $lastRead]
            );
        }

        $last = $this->app->db()->fetch(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1',
            [$conversation['id']]
        );

        return [
            'id' => $conversation['id'],
            'type' => $conversation['type'],
            'participant' => $otherUser,
            'last_message' => $last === null ? null : $this->messagePayload($last, ['id' => $userId]),
            'unread_count' => $unread,
            'last_message_at' => $conversation['last_message_at'],
            'created_at' => $conversation['created_at'],
        ];
    }

    private function membership(array $user, string $id): array
    {
        $conversation = $this->app->db()->fetch('SELECT * FROM conversations WHERE id = ? LIMIT 1', [$id]);

        if ($conversation === null) {
            throw ApiException::notFound('Conversation not found.');
        }

        $member = $this->app->db()->fetch(
            'SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?',
            [$id, $user['id']]
        );

        if ($member === null) {
            throw ApiException::notFound('Conversation not found.');
        }

        return $conversation;
    }

    private function otherMember(string $conversationId, string $userId): ?array
    {
        return $this->app->db()->fetch(
            'SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id <> ? LIMIT 1',
            [$conversationId, $userId]
        );
    }
}
