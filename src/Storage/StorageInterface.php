<?php

namespace Social\Storage;

interface StorageInterface
{
    public function put(string $path, string $contents): void;

    public function putFile(string $path, string $source): void;

    public function append(string $path, string $contents): void;

    public function get(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function size(string $path): int;

    public function path(string $path): string;

    public function url(string $path): string;
}
