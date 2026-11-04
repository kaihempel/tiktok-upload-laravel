<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Upload;

use InvalidArgumentException;

/**
 * Calculates chunks according to TikTok's Media Transfer Guide:
 *  - files smaller than 5 MB are uploaded as a single chunk (chunk_size = video_size)
 *  - otherwise chunk_size is between 5 MB and 64 MB
 *  - total_chunk_count = floor(video_size / chunk_size)
 *  - the last chunk takes the remainder and may be larger (up to 128 MB)
 *  - at most 1000 chunks
 */
final class ChunkPlanner
{
    public const int MIN_CHUNK_SIZE = 5 * 1024 * 1024;

    public const int MAX_CHUNK_SIZE = 64 * 1024 * 1024;

    public const int MAX_CHUNK_COUNT = 1000;

    public function __construct(
        private readonly int $preferredChunkSize = 10 * 1024 * 1024,
    ) {}

    public function plan(int $videoSize, ?int $preferredChunkSize = null): ChunkPlan
    {
        if ($videoSize <= 0) {
            throw new InvalidArgumentException('The video size must be greater than zero.');
        }

        $chunkSize = max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $preferredChunkSize ?? $this->preferredChunkSize));

        // Small files (and files that fit into one chunk) are uploaded as a whole.
        if ($videoSize < self::MIN_CHUNK_SIZE || $videoSize <= $chunkSize) {
            return new ChunkPlan($videoSize, $videoSize, 1);
        }

        if (intdiv($videoSize, $chunkSize) > self::MAX_CHUNK_COUNT) {
            $chunkSize = min(self::MAX_CHUNK_SIZE, (int) ceil($videoSize / self::MAX_CHUNK_COUNT));
        }

        $count = intdiv($videoSize, $chunkSize);

        // A single chunk always has to cover the whole file.
        if ($count === 1) {
            return new ChunkPlan($videoSize, $videoSize, 1);
        }

        return new ChunkPlan($videoSize, $chunkSize, $count);
    }
}
