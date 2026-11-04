<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media\UrlProviders;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use RuntimeException;

/**
 * Uses Storage::temporaryUrl(), e.g. for S3 behind an own, verified CDN domain.
 * Falls back to the permanent URL for disks without temporary URL support.
 */
class DiskUrlProvider implements MediaUrlProvider
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly int $ttlMinutes = 60,
    ) {}

    public function urlFor(string $disk, string $path): string
    {
        $filesystem = $this->filesystems->disk($disk);

        if (! $filesystem instanceof FilesystemAdapter) {
            throw new RuntimeException("Disk [{$disk}] does not support URLs.");
        }

        if ($filesystem->providesTemporaryUrls()) {
            return $filesystem->temporaryUrl($path, Carbon::now()->addMinutes($this->ttlMinutes));
        }

        return $filesystem->url($path);
    }
}
