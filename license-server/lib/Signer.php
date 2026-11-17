<?php

namespace LicenseServer;

use RuntimeException;

final class Signer
{
    public function __construct(private readonly string $privateKey)
    {
        if (openssl_pkey_get_private($this->privateKey) === false) {
            throw new RuntimeException('Invalid license signing key.');
        }
    }

    public static function generate(int $bits = 3072): array
    {
        $options = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $configFile = getenv('OPENSSL_CONF') ?: '';
        $candidates = [
            $configFile,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
            'C:/xampp/apache/conf/openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                $options['config'] = $candidate;
                break;
            }
        }

        $resource = openssl_pkey_new($options);

        if ($resource === false) {
            throw new RuntimeException('Could not generate license signing key.');
        }

        $private = '';

        if (!openssl_pkey_export($resource, $private, null, $options)) {
            throw new RuntimeException('Could not export license signing key.');
        }

        $details = openssl_pkey_get_details($resource);

        if (!is_array($details) || !isset($details['key'])) {
            throw new RuntimeException('Could not read license public key.');
        }

        return [base64_encode($private), base64_encode($details['key'])];
    }

    public static function fromBase64(string $encoded): self
    {
        $private = base64_decode($encoded, true);

        if ($private === false || $private === '') {
            throw new RuntimeException('Invalid license signing key encoding.');
        }

        return new self($private);
    }

    public static function publicFromBase64(string $encoded): string
    {
        $public = base64_decode($encoded, true);

        if ($public === false || $public === '') {
            throw new RuntimeException('Invalid license public key encoding.');
        }

        return $public;
    }

    public function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = '';

        if ($json === false || !openssl_sign($json, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign license response.');
        }

        return self::base64UrlEncode($signature);
    }

    public static function verify(array $payload, string $signature, string $publicKey): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = self::base64UrlDecode($signature);

        if ($json === false || $decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($json, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1;
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
}
