<?php

namespace Social\Support;

final class Str
{
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public static function fromTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function timestamp(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $parsed = strtotime($value . ' UTC');

        return $parsed === false ? 0 : $parsed;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    public static function normalizeTag(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = ltrim($value, '#');
        $value = preg_replace('/[^\p{L}\p{N}_]+/u', '', $value) ?? '';

        return mb_substr($value, 0, 50, 'UTF-8');
    }

    public static function normalizeUsername(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    public static function excerpt(string $value, int $length = 120): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1, 'UTF-8') . '…';
    }

    public static function ipHash(string $ip, string $key): string
    {
        return hash_hmac('sha256', $ip, $key);
    }

    public static function safePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    public static function csrfSafeEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    public static function randomFrom(string $pool, int $length): string
    {
        $max = strlen($pool) - 1;
        $value = '';

        for ($i = 0; $i < $length; $i++) {
            $value .= $pool[random_int(0, $max)];
        }

        return $value;
    }
}
