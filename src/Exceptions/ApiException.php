<?php

namespace Social\Exceptions;

class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'You are not allowed to do this.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'conflict', $message, $details);
    }

    public static function payloadTooLarge(string $message): self
    {
        return new self(413, 'payload_too_large', $message);
    }

    public static function unsupportedMedia(string $message): self
    {
        return new self(415, 'unsupported_media_type', $message);
    }

    public static function tooManyAttempts(string $message, int $retryAfter): self
    {
        return new self(429, 'too_many_requests', $message, ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }

    public static function server(string $message = 'Something went wrong.'): self
    {
        return new self(500, 'server_error', $message);
    }
}
