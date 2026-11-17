<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;

final class IntegrityService
{
    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->app->config()->get('licensing.integrity', false);
    }

    public function guard(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $manifest = $this->manifest();

        if ($manifest === null) {
            throw new ApiException(402, 'license_integrity_failed', 'The integrity manifest is missing. The platform is locked.', [
                'reason' => 'manifest_missing',
            ]);
        }

        $root = $this->root();

        foreach ($manifest['files'] as $file => $hash) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new ApiException(402, 'license_integrity_failed', 'The platform files have been modified. The platform is locked.', [
                    'reason' => 'file_modified',
                    'file' => $file,
                ]);
            }
        }
    }

    public function build(): array
    {
        $root = $this->root();
        $files = [];
        $directories = ['src', 'config', 'bin'];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }

        foreach (['autoload.php', 'server.php', 'public/index.php', 'bin/social'] as $file) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

            if (is_file($path)) {
                $files[$file] = hash_file('sha256', $path);
            }
        }

        ksort($files);
        $manifest = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'files' => $files,
        ];
        $target = (string) $this->app->config()->get('licensing.integrity_manifest');
        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($target, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $manifest;
    }

    private function manifest(): ?array
    {
        $path = (string) $this->app->config()->get('licensing.integrity_manifest');

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return null;
        }

        return $data;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
