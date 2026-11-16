<?php

namespace Social\Storage;

use Social\Support\Str;

final class LocalStorage implements StorageInterface
{
    public function __construct(private readonly string $root, private readonly string $publicUrl)
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function put(string $path, string $contents): void
    {
        $full = $this->prepare($path);
        $temporary = $full . '.' . Str::token(6) . '.tmp';

        if (file_put_contents($temporary, $contents) === false) {
            throw new \RuntimeException('Unable to write storage file.');
        }

        if (!rename($temporary, $full)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to finalize storage file.');
        }
    }

    public function putFile(string $path, string $source): void
    {
        $full = $this->prepare($path);
        $temporary = $full . '.' . Str::token(6) . '.tmp';

        if (!copy($source, $temporary)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to copy file into storage.');
        }

        if (!rename($temporary, $full)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to finalize storage file.');
        }
    }

    public function append(string $path, string $contents): void
    {
        $full = $this->prepare($path);

        if (file_put_contents($full, $contents, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Unable to append to storage file.');
        }
    }

    public function get(string $path): string
    {
        $full = $this->path($path);

        if (!is_file($full)) {
            throw new \RuntimeException('Storage file not found.');
        }

        return (string) file_get_contents($full);
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function delete(string $path): void
    {
        $full = $this->path($path);

        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function size(string $path): int
    {
        $full = $this->path($path);

        return is_file($full) ? (int) filesize($full) : 0;
    }

    public function path(string $path): string
    {
        $safe = Str::safePath($path);

        if ($safe === null) {
            throw new \InvalidArgumentException('Invalid storage path.');
        }

        return rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
    }

    public function url(string $path): string
    {
        $safe = Str::safePath($path);

        if ($safe === null) {
            return '';
        }

        $segments = array_map('rawurlencode', explode('/', $safe));

        return rtrim($this->publicUrl, '/') . '/' . implode('/', $segments);
    }

    private function prepare(string $path): string
    {
        $full = $this->path($path);
        $directory = dirname($full);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $full;
    }
}
