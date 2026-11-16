<?php

namespace Social\Services;

use Social\App;
use Social\Exceptions\ApiException;

final class PlatformService
{
    private const MODES = ['short', 'long', 'hybrid'];

    public function __construct(private readonly App $app)
    {
    }

    public function mode(): string
    {
        $mode = strtolower((string) $this->app->config()->get('platform.mode', 'hybrid'));

        return in_array($mode, self::MODES, true) ? $mode : 'hybrid';
    }

    public function shortMax(): int
    {
        return max(1, (int) $this->app->config()->get('platform.short_max_duration', 180));
    }

    public function longMin(): int
    {
        return max(1, (int) $this->app->config()->get('platform.long_min_duration', $this->shortMax()));
    }

    public function formatOf(int $duration): string
    {
        return $duration >= $this->longMin() ? 'long' : 'short';
    }

    public function allows(string $format): bool
    {
        $mode = $this->mode();

        return $mode === 'hybrid' || $mode === $format;
    }

    public function assertDuration(int $duration): void
    {
        if (!(bool) $this->app->config()->get('platform.enforce_upload_mode', true)) {
            return;
        }

        $format = $this->formatOf($duration);

        if (!$this->allows($format)) {
            $mode = $this->mode();
            $message = $mode === 'short'
                ? 'This platform only accepts short videos up to ' . $this->shortMax() . ' seconds.'
                : 'This platform only accepts long videos of at least ' . $this->longMin() . ' seconds.';
            throw ApiException::badRequest($message, [
                'platform_mode' => $mode,
                'video_format' => $format,
                'short_max_duration' => $this->shortMax(),
                'long_min_duration' => $this->longMin(),
            ]);
        }
    }

    public function formatFilter(?string $format): string
    {
        $format = strtolower(trim((string) $format));

        if (in_array($format, ['short', 'shorts'], true)) {
            return 'short';
        }

        if (in_array($format, ['long', 'longform'], true)) {
            return 'long';
        }

        if (in_array($format, ['all', 'hybrid', 'any', ''], true)) {
            $mode = $this->mode();

            return $mode === 'hybrid' ? 'all' : $mode;
        }

        throw ApiException::badRequest('Format must be one of: short, long, all.');
    }

    public function durationClause(string $format): array
    {
        if ($format === 'short') {
            return ['v.duration <= ?', [$this->shortMax()]];
        }

        if ($format === 'long') {
            return ['v.duration >= ?', [$this->longMin()]];
        }

        return ['1 = 1', []];
    }

    public function info(): array
    {
        $mode = $this->mode();

        return [
            'mode' => $mode,
            'accepts' => match ($mode) {
                'short' => ['short'],
                'long' => ['long'],
                default => ['short', 'long'],
            },
            'short_max_duration' => $this->shortMax(),
            'long_min_duration' => $this->longMin(),
            'enforce_upload_mode' => (bool) $this->app->config()->get('platform.enforce_upload_mode', true),
        ];
    }
}
