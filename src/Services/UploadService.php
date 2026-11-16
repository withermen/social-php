<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Support\Str;
use Social\Support\Validator;

final class UploadService
{
    public function __construct(private readonly App $app)
    {
    }

    public function create(array $user, array $data): array
    {
        $clean = Validator::validate($data, [
            'filename' => 'required|string:1,255',
            'size' => 'required|integer:1,536870912',
            'mime' => 'nullable|string:0,100',
            'title' => 'required|string:1,150',
            'description' => 'nullable|string:0,5000',
            'visibility' => 'nullable|in:public,unlisted,private',
        ]);
        $maxSize = (int) $this->app->config()->get('uploads.max_size', 536870912);
        $size = (int) $clean['size'];

        if ($size > $maxSize) {
            throw ApiException::payloadTooLarge('Upload exceeds the maximum allowed size.');
        }

        $extension = strtolower(pathinfo($clean['filename'], PATHINFO_EXTENSION));

        if (!in_array($extension, $this->app->config()->get('uploads.allowed_video', []), true)) {
            throw ApiException::unsupportedMedia('Unsupported video file extension.');
        }

        $id = Str::id();
        $now = Str::now();
        $this->app->db()->insert('upload_sessions', [
            'id' => $id,
            'user_id' => $user['id'],
            'filename' => $clean['filename'],
            'mime' => $clean['mime'] ?? 'application/octet-stream',
            'size' => $size,
            'received' => 0,
            'title' => $clean['title'],
            'description' => trim((string) ($clean['description'] ?? '')) ?: null,
            'visibility' => $clean['visibility'] ?? 'public',
            'status' => 'uploading',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->storage()->put('uploads/' . $id . '.part', '');

        return $this->payload($this->find($id, $user['id']));
    }

    public function status(array $user, string $id): array
    {
        return $this->payload($this->find($id, $user['id']));
    }

    public function writeChunk(array $user, string $id, string $chunk, int $offset): array
    {
        $session = $this->find($id, $user['id']);

        if ($session['status'] !== 'uploading') {
            throw ApiException::conflict('This upload session is no longer accepting chunks.');
        }

        if ($chunk === '') {
            throw ApiException::badRequest('Chunk body is empty.');
        }

        $length = strlen($chunk);
        $maxChunk = (int) $this->app->config()->get('uploads.max_chunk', 8388608);

        if ($length > $maxChunk) {
            throw ApiException::payloadTooLarge('Chunk exceeds the maximum chunk size.');
        }

        if ($offset !== (int) $session['received']) {
            throw new ApiException(409, 'offset_mismatch', 'Chunk offset does not match the received byte count.', [
                'expected_offset' => (int) $session['received'],
                'received_offset' => $offset,
            ]);
        }

        if ((int) $session['received'] + $length > (int) $session['size']) {
            throw ApiException::badRequest('Chunk would exceed the declared upload size.');
        }

        $this->app->storage()->append('uploads/' . $session['id'] . '.part', $chunk);
        $received = (int) $session['received'] + $length;
        $this->app->db()->update('upload_sessions', [
            'received' => $received,
            'updated_at' => Str::now(),
        ], 'id = ?', [$session['id']]);
        $session['received'] = $received;

        return $this->payload($session);
    }

    public function complete(array $user, string $id, array $data): array
    {
        $session = $this->find($id, $user['id']);

        if ($session['status'] === 'completed') {
            throw ApiException::conflict('This upload was already completed.');
        }

        if ($session['status'] !== 'uploading') {
            throw ApiException::conflict('This upload session is not active.');
        }

        if ((int) $session['received'] !== (int) $session['size']) {
            throw new ApiException(409, 'upload_incomplete', 'Upload is incomplete.', [
                'received' => (int) $session['received'],
                'size' => (int) $session['size'],
            ]);
        }

        $path = $this->app->storage()->path('uploads/' . $session['id'] . '.part');
        $metadata = [
            'title' => $data['title'] ?? $session['title'],
            'description' => $data['description'] ?? $session['description'],
            'visibility' => $data['visibility'] ?? $session['visibility'],
            'tags' => $data['tags'] ?? [],
        ];
        $video = $this->app->videos()->publish(
            $user,
            $path,
            $session['filename'],
            $session['mime'],
            (int) $session['size'],
            $metadata
        );
        $this->app->storage()->delete('uploads/' . $session['id'] . '.part');
        $this->app->db()->update('upload_sessions', [
            'status' => 'completed',
            'updated_at' => Str::now(),
        ], 'id = ?', [$session['id']]);

        return $video;
    }

    public function abort(array $user, string $id): void
    {
        $session = $this->find($id, $user['id']);

        if ($session['status'] === 'completed') {
            throw ApiException::conflict('Completed uploads cannot be aborted.');
        }

        $this->app->storage()->delete('uploads/' . $session['id'] . '.part');
        $this->app->db()->update('upload_sessions', [
            'status' => 'aborted',
            'updated_at' => Str::now(),
        ], 'id = ?', [$session['id']]);
    }

    private function find(string $id, string $userId): array
    {
        $session = $this->app->db()->fetch('SELECT * FROM upload_sessions WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);

        if ($session === null) {
            throw ApiException::notFound('Upload session not found.');
        }

        return $session;
    }

    private function payload(array $session): array
    {
        $size = max(1, (int) $session['size']);
        $received = (int) $session['received'];

        return [
            'id' => $session['id'],
            'status' => $session['status'],
            'filename' => $session['filename'],
            'mime' => $session['mime'],
            'size' => (int) $session['size'],
            'received' => $received,
            'remaining' => max(0, (int) $session['size'] - $received),
            'progress' => min(100, (int) round($received / $size * 100)),
            'chunk_size' => (int) $this->app->config()->get('uploads.max_chunk', 8388608),
            'title' => $session['title'],
            'description' => $session['description'],
            'visibility' => $session['visibility'],
            'created_at' => $session['created_at'],
            'updated_at' => $session['updated_at'],
        ];
    }
}
