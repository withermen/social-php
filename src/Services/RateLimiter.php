<?php

namespace Social\Services;

use Social\Database\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly bool $enabled = true)
    {
    }

    public function attempt(string $bucket, int $max, int $window): array
    {
        if (!$this->enabled || $max <= 0 || $window <= 0) {
            return ['allowed' => true, 'remaining' => $max, 'retry_after' => 0, 'hits' => 0];
        }

        $now = time();
        $start = intdiv($now, $window) * $window;
        $hits = 0;

        $this->db->transaction(function () use ($bucket, $start, $window, $max, &$hits): void {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$start - $window * 2]);
            $row = $this->db->fetch('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $start]);

            if ($row === null) {
                $hits = 1;
                $this->db->insert('rate_limits', ['bucket' => $bucket, 'window_start' => $start, 'hits' => 1]);

                return;
            }

            $hits = (int) $row['hits'];

            if ($hits < $max + 1) {
                $hits++;
                $this->db->update('rate_limits', ['hits' => $hits], 'bucket = ? AND window_start = ?', [$bucket, $start]);
            }
        });

        return [
            'allowed' => $hits <= $max,
            'remaining' => max(0, $max - $hits),
            'retry_after' => max(1, $start + $window - $now),
            'hits' => $hits,
        ];
    }

    public function clear(string $bucket): void
    {
        $this->db->delete('rate_limits', 'bucket = ?', [$bucket]);
    }
}
