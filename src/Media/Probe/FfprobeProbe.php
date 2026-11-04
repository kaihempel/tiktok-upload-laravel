<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media\Probe;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use KaiHempel\TikTokUpload\Contracts\MediaProbe;
use KaiHempel\TikTokUpload\Data\ProbeResult;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Reads codec, frame rate, duration and resolution with ffprobe. Files on
 * non-local disks (e.g. S3) are streamed into a temporary file first.
 */
class FfprobeProbe implements MediaProbe
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly string $binary = 'ffprobe',
        private readonly int $timeout = 60,
    ) {}

    public function probe(string $disk, string $path): ProbeResult
    {
        [$localPath, $temporary] = $this->localPath($disk, $path);

        try {
            $result = Process::timeout($this->timeout)->run([
                $this->binary,
                '-v', 'error',
                '-select_streams', 'v:0',
                '-show_entries', 'stream=codec_name,width,height,avg_frame_rate,r_frame_rate:format=duration',
                '-of', 'json',
                $localPath,
            ]);
        } finally {
            if ($temporary) {
                @unlink($localPath);
            }
        }

        if ($result->failed()) {
            throw new TikTokException('ffprobe failed: '.trim($result->errorOutput()), 'probe_failed');
        }

        return self::parse($result->output());
    }

    public static function parse(string $json): ProbeResult
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new TikTokException('ffprobe returned invalid JSON.', 'probe_failed');
        }

        $stream = is_array($data['streams'][0] ?? null) ? $data['streams'][0] : [];
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];

        $fps = self::fps((string) ($stream['avg_frame_rate'] ?? ''))
            ?? self::fps((string) ($stream['r_frame_rate'] ?? ''));

        return new ProbeResult(
            codec: isset($stream['codec_name']) ? strtolower((string) $stream['codec_name']) : null,
            fps: $fps,
            durationSeconds: isset($format['duration']) && is_numeric($format['duration']) ? (float) $format['duration'] : null,
            width: isset($stream['width']) ? (int) $stream['width'] : null,
            height: isset($stream['height']) ? (int) $stream['height'] : null,
        );
    }

    private static function fps(string $rate): ?float
    {
        if ($rate === '' || $rate === '0/0') {
            return null;
        }

        if (str_contains($rate, '/')) {
            [$numerator, $denominator] = array_map(floatval(...), explode('/', $rate, 2));

            return $denominator > 0 ? round($numerator / $denominator, 3) : null;
        }

        return is_numeric($rate) ? (float) $rate : null;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function localPath(string $disk, string $path): array
    {
        $filesystem = $this->filesystems->disk($disk);

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return [$filesystem->path($path), false];
        }

        $stream = $filesystem->readStream($path);
        $temporary = tempnam(sys_get_temp_dir(), 'tiktok-probe-');

        if (! is_resource($stream) || $temporary === false) {
            throw new TikTokException("Unable to read [{$path}] from disk [{$disk}] for probing.", 'probe_failed');
        }

        $target = fopen($temporary, 'wb');

        if ($target === false) {
            throw new TikTokException('Unable to create a temporary file for probing.', 'probe_failed');
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return [$temporary, true];
    }
}
