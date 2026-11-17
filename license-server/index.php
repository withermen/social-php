<?php

declare(strict_types=1);

use LicenseServer\Server;
use LicenseServer\Signer;
use LicenseServer\Store;

$root = __DIR__;
$configPath = $root . '/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'not_configured',
        'message' => 'Create config.php from config.example.php and run php bin/license init.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$config = require $configPath;

require $root . '/lib/Signer.php';
require $root . '/lib/Store.php';
require $root . '/lib/Server.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = (array) ($config['cors'] ?? ['*']);

if (in_array('*', $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
$base = rtrim((string) ($config['base_path'] ?? ''), '/');

if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

if (str_starts_with($path, '/index.php')) {
    $path = substr($path, strlen('/index.php'));
}

$path = '/' . trim($path, '/');

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

$raw = file_get_contents('php://input') ?: '';
$body = [];

if (trim($raw) !== '') {
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_json', 'message' => 'Request body must be valid JSON.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $body = $decoded;
}

$nonce = (string) ($body['nonce'] ?? '');

try {
    $signer = is_file((string) $config['private_key_file'])
        ? Signer::fromBase64(trim((string) file_get_contents((string) $config['private_key_file'])))
        : null;
    $server = new Server($config, new Store((string) $config['database']), $signer);
    [$status, $payload] = $server->handle($method, $path, $body, $headers, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
} catch (Throwable $exception) {
    $status = 500;
    $payload = [
        'ok' => false,
        'error' => 'server_error',
        'message' => (bool) ($config['debug'] ?? false) ? $exception->getMessage() : 'License server error.',
        'ts' => time(),
        'nonce' => $nonce,
    ];
}

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
