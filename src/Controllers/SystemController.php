<?php

namespace Social\Controllers;

use Social\Http\Request;
use Social\Http\Response;
use Social\Exceptions\ApiException;
use Social\Support\Str;

final class SystemController extends Controller
{
    public function index(): Response
    {
        return Response::data([
            'name' => $this->app->config()->get('app.name', 'SocialPHP'),
            'version' => $this->app->config()->get('app.version', '1.0.0'),
            'api' => '/api/v1',
            'health' => '/api/v1/health',
            'authentication' => 'Bearer',
            'features' => [
                'accounts',
                'profiles',
                'follows',
                'blocks',
                'mutes',
                'video_uploads',
                'resumable_uploads',
                'range_streaming',
                'likes',
                'comments',
                'saves',
                'shares',
                'feeds',
                'hashtags',
                'mentions',
                'notifications',
                'messages',
                'search',
                'reports',
                'moderation',
            ],
        ]);
    }

    public function health(): Response
    {
        $database = false;

        try {
            $database = (int) $this->app->db()->value('SELECT 1') === 1;
        } catch (\Throwable) {
            $database = false;
        }

        $storage = is_dir((string) $this->app->config()->get('storage.root')) && is_writable((string) $this->app->config()->get('storage.root'));

        return Response::data([
            'status' => $database && $storage ? 'ok' : 'degraded',
            'database' => $database,
            'storage' => $storage,
            'time' => Str::now(),
            'version' => $this->app->config()->get('app.version', '1.0.0'),
        ], [], $database && $storage ? 200 : 503);
    }

    public function media(Request $request, string $path): Response
    {
        $safe = Str::safePath($path);

        if ($safe === null) {
            throw ApiException::notFound('Media not found.');
        }

        $full = $this->app->storage()->path($safe);

        if (!is_file($full)) {
            throw ApiException::notFound('Media not found.');
        }

        $mime = mime_content_type($full) ?: 'application/octet-stream';

        if (!str_starts_with($mime, 'image/')) {
            throw ApiException::notFound('Media not found.');
        }

        return Response::file($full, $mime, $request->header('range'), [
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }
}
