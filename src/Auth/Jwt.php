<?php

namespace Social\Auth;

use Social\Exceptions\ApiException;
use Social\Support\Str;

final class Jwt
{
    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly int $ttl
    ) {
        if (strlen($this->secret) < 16) {
            throw new \RuntimeException('APP_KEY must be at least 16 characters.');
        }
    }

    public function issue(string $subject, array $claims = []): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iss' => $this->issuer,
            'sub' => $subject,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => Str::token(12),
        ]);
        $segments = [
            Str::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            Str::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $this->secret, true);
        $segments[] = Str::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public function parse(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw ApiException::unauthorized('Invalid access token.');
        }

        [$headerPart, $payloadPart, $signaturePart] = $segments;
        $header = json_decode((string) Str::base64UrlDecode($headerPart), true);
        $payload = json_decode((string) Str::base64UrlDecode($payloadPart), true);

        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'HS256') {
            throw ApiException::unauthorized('Invalid access token.');
        }

        $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, $this->secret, true);
        $provided = Str::base64UrlDecode($signaturePart);

        if ($provided === false || !hash_equals($expected, $provided)) {
            throw ApiException::unauthorized('Invalid access token.');
        }

        $now = time();

        if (($payload['iss'] ?? null) !== $this->issuer || ($payload['sub'] ?? null) === null) {
            throw ApiException::unauthorized('Invalid access token.');
        }

        if ((int) ($payload['exp'] ?? 0) <= $now) {
            throw ApiException::unauthorized('Access token has expired.');
        }

        if ((int) ($payload['nbf'] ?? 0) > $now + 30) {
            throw ApiException::unauthorized('Access token is not active yet.');
        }

        return $payload;
    }
}
