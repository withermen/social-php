<?php

namespace Social\Http;

use Social\Exceptions\ApiException;

final class Request
{
    private array $parsed = [];
    private bool $parsedBody = false;
    private ?array $user = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private array $headers = [],
        public readonly string $body = '',
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $post = []
    ) {
        $this->headers = self::normalizeHeaders($headers);
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $files = [];

        foreach ($_FILES as $key => $file) {
            if (is_array($file) && isset($file['tmp_name']) && !is_array($file['tmp_name'])) {
                $files[$key] = UploadedFile::fromArray($file);
            }
        }

        return new self(
            $method,
            '/' . ltrim(rawurldecode($path), '/'),
            $_GET,
            is_array($headers) ? $headers : [],
            (string) file_get_contents('php://input'),
            $files,
            $_SERVER,
            $_POST
        );
    }

    public function data(): array
    {
        if ($this->parsedBody) {
            return $this->parsed;
        }

        $this->parsedBody = true;
        $contentType = strtolower($this->header('content-type', ''));

        if (str_contains($contentType, 'application/json')) {
            if (trim($this->body) === '') {
                $this->parsed = [];

                return $this->parsed;
            }

            try {
                $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ApiException::badRequest('Request body contains invalid JSON.');
            }

            if (!is_array($decoded)) {
                throw ApiException::badRequest('JSON body must be an object.');
            }

            $this->parsed = $decoded;

            return $this->parsed;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') && $this->body !== '') {
            $parsed = [];
            parse_str($this->body, $parsed);
            $this->parsed = $parsed;

            return $this->parsed;
        }

        $this->parsed = $this->post;

        return $this->parsed;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data();
    }

    public function only(array $keys): array
    {
        $data = $this->data();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '') ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function ip(bool $trustProxy = false): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');

        if ($trustProxy) {
            $forwarded = $this->header('x-forwarded-for');

            if ($forwarded !== null && $forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '127.0.0.1';
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('user-agent', ''), 0, 500);
    }

    public function setUser(?array $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function userId(): ?string
    {
        return $this->user['id'] ?? null;
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $normalized[strtolower($name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $normalized;
    }
}
