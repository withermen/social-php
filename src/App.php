<?php

namespace Social;

use Social\Auth\AuthService;
use Social\Auth\Jwt;
use Social\Database\Database;
use Social\Exceptions\ApiException;
use Social\Exceptions\ValidationException;
use Social\Http\Request;
use Social\Http\Response;
use Social\Http\Router;
use Social\Mail\LogMailer;
use Social\Mail\MailerInterface;
use Social\Media\MediaProcessor;
use Social\Services\AdminService;
use Social\Services\AdService;
use Social\Services\AnalysisService;
use Social\Services\CommentService;
use Social\Services\FeedService;
use Social\Services\IntegrityService;
use Social\Services\LicenseService;
use Social\Services\MessageService;
use Social\Services\NotificationService;
use Social\Services\PlatformService;
use Social\Services\PremiumService;
use Social\Services\RateLimiter;
use Social\Services\RecommendationService;
use Social\Services\RemoteLicenseClient;
use Social\Services\ReportService;
use Social\Services\SearchService;
use Social\Services\UploadService;
use Social\Services\UserService;
use Social\Services\VideoService;
use Social\Storage\LocalStorage;
use Social\Storage\StorageInterface;
use Social\Support\Config;
use Social\Support\Str;

final class App
{
    private Config $config;
    private Database $db;
    private StorageInterface $storage;
    private MediaProcessor $media;
    private MailerInterface $mailer;
    private Jwt $jwt;
    private RateLimiter $rateLimiter;
    private Router $router;
    private array $services = [];

    public function __construct(Config|array $config)
    {
        $this->config = $config instanceof Config ? $config : new Config($config);
        date_default_timezone_set((string) $this->config->get('app.timezone', 'UTC'));
        $logDirectory = dirname((string) $this->config->get('mail.log_path', __DIR__ . '/../storage/logs/mail.log'));

        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0775, true);
        }

        $this->db = new Database((array) $this->config->get('database', []));

        if ((bool) $this->config->get('database.auto_migrate', true)) {
            $this->db->migrate();
        }

        $this->storage = new LocalStorage(
            (string) $this->config->get('storage.root'),
            (string) $this->config->get('storage.public_url', '/media')
        );
        $this->media = new MediaProcessor((array) $this->config->get('media', []));
        $this->mailer = new LogMailer(
            (string) $this->config->get('mail.log_path'),
            (string) $this->config->get('mail.from', 'no-reply@example.com')
        );
        $this->jwt = new Jwt(
            (string) $this->config->get('app.key'),
            (string) ($this->config->get('app.url') ?: $this->config->get('app.name', 'SocialPHP')),
            (int) $this->config->get('auth.access_ttl', 3600)
        );
        $this->rateLimiter = new RateLimiter($this->db, (bool) $this->config->get('rate_limit.enabled', true));
        $this->router = new Router($this);
        $routes = require __DIR__ . '/routes.php';
        $routes($this, $this->router);
    }

    public function handle(Request $request): Response
    {
        $requestId = Str::id();

        if (strtoupper($request->method) === 'OPTIONS') {
            $response = Response::noContent();
            $this->applyCors($response, $request);
            $response->header('X-Request-Id', $requestId);

            return $response;
        }

        $started = microtime(true);
        $licenseStatus = null;
        $licenseExempt = str_starts_with($request->path, '/api/v1/license')
            || $request->path === '/api/v1/health'
            || $request->path === '/api/v1'
            || $request->path === '/';

        try {
            if (!$licenseExempt) {
                $this->integrity()->guard();
                $licenseStatus = $this->licenses()->guard();
            }

            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = Response::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        } catch (ApiException $exception) {
            $response = Response::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details, $exception->headers);

            if ($exception->status === 401) {
                $response->header('WWW-Authenticate', 'Bearer');
            }
        } catch (\Throwable $exception) {
            $this->logError($request, $exception, $requestId);
            $details = $this->config->get('app.debug', false) ? [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'line' => $exception->getLine(),
                'file' => $exception->getFile(),
            ] : [];
            $response = Response::error('server_error', 'Something went wrong.', 500, $details);
        }

        $response->header('X-Request-Id', $requestId);
        $response->header('X-Response-Time', (string) round((microtime(true) - $started) * 1000, 2));
        $licenseStatus ??= $this->licenses()->status();
        $response->header('X-License-Status', $licenseStatus['valid'] ? ($licenseStatus['licensed'] ? 'licensed' : 'not-required') : 'invalid');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'no-referrer');
        $this->applyCors($response, $request);

        return $response;
    }

    public function resolveUser(Request $request, bool $required = true): ?array
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $claims = $this->jwt->parse($token);
        $user = $this->db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [(string) $claims['sub']]);

        if ($user === null) {
            if ($required) {
                throw ApiException::unauthorized('Account no longer exists.');
            }

            return null;
        }

        if ($user['status'] !== 'active') {
            throw ApiException::forbidden('This account is not available.');
        }

        if ($user['last_seen_at'] === null || Str::timestamp($user['last_seen_at']) < time() - 300) {
            $this->db->update('users', ['last_seen_at' => Str::now()], 'id = ?', [$user['id']]);
        }

        return $user;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    public function media(): MediaProcessor
    {
        return $this->media;
    }

    public function mailer(): MailerInterface
    {
        return $this->mailer;
    }

    public function jwt(): Jwt
    {
        return $this->jwt;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function auth(): AuthService
    {
        return $this->services[AuthService::class] ??= new AuthService($this);
    }

    public function users(): UserService
    {
        return $this->services[UserService::class] ??= new UserService($this);
    }

    public function videos(): VideoService
    {
        return $this->services[VideoService::class] ??= new VideoService($this);
    }

    public function uploads(): UploadService
    {
        return $this->services[UploadService::class] ??= new UploadService($this);
    }

    public function comments(): CommentService
    {
        return $this->services[CommentService::class] ??= new CommentService($this);
    }

    public function feeds(): FeedService
    {
        return $this->services[FeedService::class] ??= new FeedService($this);
    }

    public function notifications(): NotificationService
    {
        return $this->services[NotificationService::class] ??= new NotificationService($this);
    }

    public function search(): SearchService
    {
        return $this->services[SearchService::class] ??= new SearchService($this);
    }

    public function messages(): MessageService
    {
        return $this->services[MessageService::class] ??= new MessageService($this);
    }

    public function reports(): ReportService
    {
        return $this->services[ReportService::class] ??= new ReportService($this);
    }

    public function admin(): AdminService
    {
        return $this->services[AdminService::class] ??= new AdminService($this);
    }

    public function platform(): PlatformService
    {
        return $this->services[PlatformService::class] ??= new PlatformService($this);
    }

    public function analysis(): AnalysisService
    {
        return $this->services[AnalysisService::class] ??= new AnalysisService($this);
    }

    public function recommendations(): RecommendationService
    {
        return $this->services[RecommendationService::class] ??= new RecommendationService($this);
    }

    public function licenses(): LicenseService
    {
        return $this->services[LicenseService::class] ??= new LicenseService($this);
    }

    public function remoteLicense(): RemoteLicenseClient
    {
        return $this->services[RemoteLicenseClient::class] ??= new RemoteLicenseClient($this);
    }

    public function integrity(): IntegrityService
    {
        return $this->services[IntegrityService::class] ??= new IntegrityService($this);
    }

    public function premium(): PremiumService
    {
        return $this->services[PremiumService::class] ??= new PremiumService($this);
    }

    public function ads(): AdService
    {
        return $this->services[AdService::class] ??= new AdService($this);
    }

    public function url(string $path = ''): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) $this->config->get('app.url'), '/');

        return $base . '/' . ltrim($path, '/');
    }

    public function assetUrl(string $path): string
    {
        $url = $this->storage->url($path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $this->url($url);
    }

    public function trustProxy(): bool
    {
        return (bool) $this->config->get('app.trust_proxy', false);
    }

    private function applyCors(Response $response, Request $request): void
    {
        $allowed = (array) $this->config->get('cors.allowed_origins', ['*']);
        $origin = $request->header('origin');
        $wildcard = in_array('*', $allowed, true);

        if ($origin !== null && ($wildcard || in_array($origin, $allowed, true))) {
            $response->header('Access-Control-Allow-Origin', $wildcard ? '*' : $origin);

            if (!$wildcard) {
                $response->header('Vary', 'Origin');

                if (in_array($origin, $allowed, true)) {
                    $response->header('Access-Control-Allow-Credentials', 'true');
                }
            }
        } elseif ($wildcard) {
            $response->header('Access-Control-Allow-Origin', '*');
        }

        $response->header('Access-Control-Allow-Methods', implode(', ', (array) $this->config->get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])));
        $response->header('Access-Control-Allow-Headers', implode(', ', (array) $this->config->get('cors.allowed_headers', ['Content-Type', 'Authorization'])));
        $response->header('Access-Control-Expose-Headers', implode(', ', (array) $this->config->get('cors.exposed_headers', ['Content-Range'])));
        $response->header('Access-Control-Max-Age', (string) $this->config->get('cors.max_age', 86400));
    }

    private function logError(Request $request, \Throwable $exception, string $requestId): void
    {
        $path = (string) $this->config->get('mail.log_path', __DIR__ . '/../storage/logs/mail.log');
        $logPath = dirname($path) . '/error.log';
        $entry = '[' . gmdate('Y-m-d H:i:s') . '] ' . $requestId . ' ' . $request->method . ' ' . $request->path . PHP_EOL
            . $exception::class . ': ' . $exception->getMessage() . PHP_EOL
            . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL
            . $exception->getTraceAsString() . PHP_EOL
            . str_repeat('-', 60) . PHP_EOL;
        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
