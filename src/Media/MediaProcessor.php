<?php

namespace Social\Media;

final class MediaProcessor
{
    public function __construct(private readonly array $config)
    {
    }

    public function probe(string $path): ?array
    {
        [$success, $output] = $this->run([
            (string) ($this->config['ffprobe'] ?? 'ffprobe'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,duration',
            '-show_entries', 'format=duration',
            '-of', 'json',
            $path,
        ]);

        if (!$success) {
            return null;
        }

        $data = json_decode($output, true);

        if (!is_array($data)) {
            return null;
        }

        $stream = $data['streams'][0] ?? [];
        $format = $data['format'] ?? [];
        $duration = (float) ($stream['duration'] ?? $format['duration'] ?? 0);

        return [
            'duration' => max(0, (int) round($duration)),
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
        ];
    }

    public function metadata(string $path): array
    {
        [$success, $output] = $this->run([
            (string) ($this->config['ffprobe'] ?? 'ffprobe'),
            '-v', 'error',
            '-show_streams',
            '-show_format',
            '-of', 'json',
            $path,
        ]);

        if (!$success) {
            return [];
        }

        $data = json_decode($output, true);

        if (!is_array($data)) {
            return [];
        }

        $video = null;
        $audio = null;

        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }

            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        $format = $data['format'] ?? [];
        $frameRate = 0.0;

        if (isset($video['avg_frame_rate']) && preg_match('/^(\d+)\/(\d+)$/', (string) $video['avg_frame_rate'], $matches)) {
            $divisor = (int) $matches[2];
            $frameRate = $divisor > 0 ? (int) $matches[1] / $divisor : 0.0;
        }

        return [
            'duration' => (float) ($video['duration'] ?? $format['duration'] ?? 0),
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'fps' => $frameRate,
            'bitrate' => (int) ($format['bit_rate'] ?? $video['bit_rate'] ?? 0),
            'video_codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'has_audio' => $audio !== null,
            'channels' => (int) ($audio['channels'] ?? 0),
            'sample_rate' => (int) ($audio['sample_rate'] ?? 0),
            'tags' => (array) ($format['tags'] ?? []),
        ];
    }

    public function sceneCuts(string $path, float $threshold = 0.32, int $limitSeconds = 900): array
    {
        $filter = "select='gt(scene," . max(0.05, min(0.95, $threshold)) . ")',showinfo";
        [$success, $output] = $this->run([
            (string) ($this->config['ffmpeg'] ?? 'ffmpeg'),
            '-i', $path,
            '-t', (string) max(1, $limitSeconds),
            '-an',
            '-filter:v', $filter,
            '-f', 'null',
            '-',
        ]);

        if (!$success) {
            return [];
        }

        preg_match_all('/pts_time:([0-9]+(?:\.[0-9]+)?)/', $output, $matches);

        return array_values(array_filter(array_map('floatval', $matches[1] ?? []), fn (float $time): bool => $time > 0));
    }

    public function audioStats(string $path, int $duration = 0): ?array
    {
        [$success, $output] = $this->run([
            (string) ($this->config['ffmpeg'] ?? 'ffmpeg'),
            '-i', $path,
            '-vn',
            '-af', 'volumedetect,silencedetect=noise=-35dB:d=0.4',
            '-f', 'null',
            '-',
        ]);

        if (!$success) {
            return null;
        }

        $loudness = null;
        $peak = null;

        if (preg_match('/mean_volume:\s*(-?[0-9.]+)\s*dB/', $output, $match)) {
            $loudness = (float) $match[1];
        }

        if (preg_match('/max_volume:\s*(-?[0-9.]+)\s*dB/', $output, $match)) {
            $peak = (float) $match[1];
        }

        $silenceSeconds = 0.0;

        if (preg_match_all('/silence_duration:\s*([0-9.]+)/', $output, $matches)) {
            foreach ($matches[1] as $value) {
                $silenceSeconds += (float) $value;
            }
        }

        return [
            'loudness' => $loudness,
            'peak' => $peak,
            'silence_seconds' => $silenceSeconds,
            'silence_ratio' => $duration > 0 ? min(1.0, $silenceSeconds / $duration) : 0.0,
        ];
    }

    public function frames(string $videoPath, string $directory, int $duration, int $count): array
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $count = max(1, min(12, $count));
        $width = (int) ($this->config['frame_width'] ?? 160);
        $width = max(64, min(640, $width));
        $frames = [];

        for ($index = 0; $index < $count; $index++) {
            $position = $duration > 1 ? $duration * (($index + 0.5) / $count) : 0.0;
            $output = $directory . DIRECTORY_SEPARATOR . 'frame-' . $index . '.jpg';
            [$success] = $this->run([
                (string) ($this->config['ffmpeg'] ?? 'ffmpeg'),
                '-y',
                '-ss', (string) round($position, 3),
                '-i', $videoPath,
                '-frames:v', '1',
                '-vf', 'scale=' . $width . ':-1',
                '-q:v', '4',
                $output,
            ]);

            if ($success && is_file($output) && filesize($output) > 0) {
                $frames[] = $output;
            }
        }

        return $frames;
    }

    public function thumbnail(string $videoPath, string $outputPath, int $duration = 0): bool
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $seek = $duration > 4 ? min(3.0, $duration / 3) : 0.0;
        $width = (int) ($this->config['thumbnail_width'] ?? 720);
        $temporary = $outputPath . '.tmp.jpg';
        [$success] = $this->run([
            (string) ($this->config['ffmpeg'] ?? 'ffmpeg'),
            '-y',
            '-ss', (string) $seek,
            '-i', $videoPath,
            '-frames:v', '1',
            '-vf', 'scale=' . $width . ':-1',
            '-q:v', '3',
            $temporary,
        ]);

        if ($success && is_file($temporary) && filesize($temporary) > 0) {
            rename($temporary, $outputPath);

            return true;
        }

        @unlink($temporary);

        return $this->placeholder($outputPath, basename($videoPath));
    }

    public function placeholder(string $outputPath, string $seed): bool
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!function_exists('imagecreatetruecolor')) {
            $minimal = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z') ?: '';

            return file_put_contents($outputPath, $minimal) !== false;
        }

        $width = (int) ($this->config['thumbnail_width'] ?? 720);
        $height = (int) round($width * 9 / 16);
        $image = imagecreatetruecolor($width, $height);
        $hash = hexdec(substr(hash('sha256', $seed), 0, 8));
        $hue = ($hash % 360) / 360;
        $top = $this->hsvToRgb($hue, 0.58, 0.28);
        $bottom = $this->hsvToRgb(fmod($hue + 0.12, 1.0), 0.65, 0.1);

        for ($y = 0; $y < $height; $y += 2) {
            $ratio = $y / max(1, $height);
            $color = imagecolorallocate(
                $image,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $ratio),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $ratio),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $ratio)
            );
            imagefilledrectangle($image, 0, $y, $width, $y + 1, $color);
        }

        $accent = imagecolorallocatealpha($image, 255, 255, 255, 92);
        imagefilledellipse($image, (int) ($width * 0.78), (int) ($height * 0.24), (int) ($width * 0.5), (int) ($width * 0.5), $accent);
        imagefilledellipse($image, (int) ($width * 0.18), (int) ($height * 0.82), (int) ($width * 0.36), (int) ($width * 0.36), $accent);
        $play = imagecolorallocatealpha($image, 255, 255, 255, 48);
        $centerX = (int) ($width / 2);
        $centerY = (int) ($height / 2);
        $radius = (int) ($height * 0.16);
        imagefilledellipse($image, $centerX, $centerY, $radius * 2, $radius * 2, $play);
        $result = imagejpeg($image, $outputPath, 82);
        imagedestroy($image);

        return $result;
    }

    public function avatar(string $source, string $outputPath, int $size = 400): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = @file_get_contents($source);

        if ($contents === false) {
            return false;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $sourceX = (int) (($width - $side) / 2);
        $sourceY = (int) (($height - $side) / 2);
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $white);
        imagecopyresampled($canvas, $image, 0, 0, $sourceX, $sourceY, $size, $size, $side, $side);
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $quality = (int) ($this->config['avatar_quality'] ?? 88);
        $result = imagejpeg($canvas, $outputPath, $quality);
        imagedestroy($canvas);
        imagedestroy($image);

        return $result;
    }

    public function imageCover(string $source, string $outputPath, int $width = 720): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = @file_get_contents($source);

        if ($contents === false) {
            return false;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return false;
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $height = (int) max(1, round($width * $sourceHeight / max(1, $sourceWidth)));
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $quality = (int) ($this->config['avatar_quality'] ?? 88);
        $result = imagejpeg($canvas, $outputPath, $quality);
        imagedestroy($canvas);
        imagedestroy($image);

        return $result;
    }

    public function isVideo(string $mime, string $extension, array $allowedExtensions, array $allowedMimes): bool
    {
        return in_array(strtolower($extension), $allowedExtensions, true)
            && in_array(strtolower($mime), $allowedMimes, true);
    }

    public function isImage(string $mime, string $extension, array $allowedExtensions, array $allowedMimes): bool
    {
        return in_array(strtolower($extension), $allowedExtensions, true)
            && in_array(strtolower($mime), $allowedMimes, true);
    }

    private function hsvToRgb(float $h, float $s, float $v): array
    {
        $i = (int) floor($h * 6);
        $f = $h * 6 - $i;
        $p = $v * (1 - $s);
        $q = $v * (1 - $f * $s);
        $t = $v * (1 - (1 - $f) * $s);

        [$r, $g, $b] = match ($i % 6) {
            0 => [$v, $t, $p],
            1 => [$q, $v, $p],
            2 => [$p, $v, $t],
            3 => [$p, $q, $v],
            4 => [$t, $p, $v],
            default => [$v, $p, $q],
        };

        return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
    }

    private function run(array $arguments): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [false, ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $output = '';
        $timeout = (int) ($this->config['timeout'] ?? 30);
        $exitCode = -1;

        while (true) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $output .= ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr);
            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) - $started > $timeout) {
                proc_terminate($process);
                break;
            }

            usleep(50000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);

        if ($exitCode < 0 && $closed >= 0) {
            $exitCode = $closed;
        }

        return [$exitCode === 0, trim($output)];
    }
}
