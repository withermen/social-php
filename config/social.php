<?php

use Social\Support\Env;

$basePath = dirname(__DIR__);

Env::load($basePath . '/.env');

return [
    'app' => [
        'name' => Env::string('APP_NAME', 'SocialPHP'),
        'version' => '1.0.0',
        'env' => Env::string('APP_ENV', 'local'),
        'debug' => Env::bool('APP_DEBUG', true),
        'url' => rtrim(Env::string('APP_URL', 'http://localhost:8000'), '/'),
        'key' => Env::string('APP_KEY', 'socialphp-development-key-change-me'),
        'timezone' => 'UTC',
        'trust_proxy' => Env::bool('TRUST_PROXY', false),
    ],
    'database' => [
        'driver' => Env::string('DB_DRIVER', 'sqlite'),
        'database' => Env::string('DB_DATABASE', $basePath . '/database/social.sqlite'),
        'host' => Env::string('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'name' => Env::string('DB_NAME', 'social'),
        'user' => Env::string('DB_USER', 'root'),
        'password' => Env::string('DB_PASSWORD', ''),
        'auto_migrate' => true,
    ],
    'auth' => [
        'access_ttl' => Env::int('AUTH_ACCESS_TTL', 3600),
        'refresh_ttl' => Env::int('AUTH_REFRESH_TTL', 2592000),
        'verification_ttl' => 86400,
        'reset_ttl' => 3600,
        'expose_tokens' => Env::bool('AUTH_EXPOSE_TOKENS', false),
        'max_login_attempts' => 10,
        'login_window' => 300,
        'max_register_attempts' => 20,
        'register_window' => 3600,
    ],
    'storage' => [
        'driver' => Env::string('STORAGE_DRIVER', 'local'),
        'root' => Env::string('STORAGE_ROOT', $basePath . '/storage/app'),
        'public_url' => rtrim(Env::string('STORAGE_URL', '/media'), '/'),
    ],
    'uploads' => [
        'max_size' => Env::int('UPLOAD_MAX_SIZE', 536870912),
        'max_chunk' => Env::int('UPLOAD_MAX_CHUNK', 8388608),
        'max_duration' => Env::int('UPLOAD_MAX_DURATION', 600),
        'allowed_video' => ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv'],
        'allowed_video_mimes' => [
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/x-matroska',
            'video/x-msvideo',
            'video/x-m4v',
            'video/ogg',
            'application/octet-stream',
        ],
        'allowed_image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ],
    'media' => [
        'ffmpeg' => Env::string('MEDIA_FFMPEG', 'ffmpeg'),
        'ffprobe' => Env::string('MEDIA_FFPROBE', 'ffprobe'),
        'timeout' => Env::int('MEDIA_TIMEOUT', 30),
        'thumbnail_width' => 720,
        'avatar_size' => 400,
        'avatar_quality' => 88,
    ],
    'mail' => [
        'driver' => Env::string('MAIL_DRIVER', 'log'),
        'from' => Env::string('MAIL_FROM', 'no-reply@example.com'),
        'log_path' => Env::string('MAIL_LOG_PATH', $basePath . '/storage/logs/mail.log'),
    ],
    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100,
    ],
    'rate_limit' => [
        'enabled' => true,
        'default_max' => 300,
        'default_window' => 60,
    ],
    'feed' => [
        'candidate_limit' => 240,
        'window_days' => 14,
        'half_life_hours' => 72,
        'max_per_author' => 2,
    ],
    'cors' => [
        'allowed_origins' => Env::list('CORS_ALLOWED_ORIGINS', ['*']),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Content-Range', 'Accept'],
        'exposed_headers' => ['Content-Range', 'X-Request-Id', 'Retry-After'],
        'max_age' => 86400,
    ],
    'trusted_proxy' => Env::bool('TRUST_PROXY', false),
];
