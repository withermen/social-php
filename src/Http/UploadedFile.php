<?php

namespace Social\Http;

final class UploadedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK
    ) {
    }

    public static function fromArray(array $file): self
    {
        return new self(
            (string) ($file['tmp_name'] ?? ''),
            (string) ($file['name'] ?? 'upload'),
            (string) ($file['type'] ?? 'application/octet-stream'),
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_OK)
        );
    }

    public static function fromPath(string $path, ?string $name = null, ?string $mime = null): self
    {
        $size = is_file($path) ? (int) filesize($path) : 0;
        $mime ??= is_file($path) ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';

        return new self($path, $name ?? basename($path), $mime, $size);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->path !== '' && is_file($this->path) && $this->size > 0;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function detectedMime(): string
    {
        if (!is_file($this->path)) {
            return $this->mime;
        }

        return mime_content_type($this->path) ?: $this->mime;
    }

    public function contents(): string
    {
        if (!is_file($this->path)) {
            return '';
        }

        return (string) file_get_contents($this->path);
    }

    public function moveTo(string $destination): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }

        if (is_uploaded_file($this->path)) {
            return move_uploaded_file($this->path, $destination);
        }

        return rename($this->path, $destination);
    }
}
