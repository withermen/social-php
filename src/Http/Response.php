<?php

namespace Social\Http;

final class Response
{
    public ?string $filePath = null;

    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self($status, $body === false ? '{}' : $body, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
    }

    public static function data(mixed $data, array $meta = [], int $status = 200): self
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status, $headers);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): self
    {
        return new self($status, $body, ['Content-Type' => $contentType]);
    }

    public static function file(string $path, string $mime, ?string $range = null, array $headers = []): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return self::error('not_found', 'File not found.', 404);
        }

        $size = (int) filesize($path);
        $response = new self(200, '', array_merge([
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400',
        ], $headers));
        $response->filePath = $path;

        if ($range === null || trim($range) === '') {
            return $response;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $matches)) {
            return self::rangeNotSatisfiable($size);
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return self::rangeNotSatisfiable($size);
        }

        if ($startRaw === '') {
            $suffix = (int) $endRaw;

            if ($suffix <= 0) {
                return self::rangeNotSatisfiable($size);
            }

            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $startRaw;
            $end = $endRaw === '' ? $size - 1 : min((int) $endRaw, $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return self::rangeNotSatisfiable($size);
        }

        $length = $end - $start + 1;
        $response->status = 206;
        $response->headers['Content-Length'] = (string) $length;
        $response->headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;

        return $response;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function send(bool $headOnly = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($headOnly) {
            return;
        }

        if ($this->filePath === null) {
            echo $this->body;

            return;
        }

        $handle = fopen($this->filePath, 'rb');

        if ($handle === false) {
            return;
        }

        if (isset($this->headers['Content-Range'])) {
            preg_match('/bytes (\d+)-(\d+)\/\d+/', $this->headers['Content-Range'], $match);

            if (isset($match[1])) {
                fseek($handle, (int) $match[1]);
            }
        }

        $remaining = (int) ($this->headers['Content-Length'] ?? 0);

        while (!feof($handle) && $remaining > 0) {
            $chunk = fread($handle, min(65536, $remaining));

            if ($chunk === false) {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($handle);
    }

    private static function rangeNotSatisfiable(int $size): self
    {
        return self::error('range_not_satisfiable', 'Requested range is not available.', 416, [], [
            'Content-Range' => 'bytes */' . $size,
        ]);
    }
}
