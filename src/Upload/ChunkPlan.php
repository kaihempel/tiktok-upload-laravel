<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Upload;

final readonly class ChunkPlan
{
    public function __construct(
        public int $videoSize,
        public int $chunkSize,
        public int $totalChunkCount,
    ) {}

    /**
     * Byte ranges of all chunks. The last chunk takes the remainder and may
     * therefore be larger than chunk_size. `end` is inclusive (Content-Range).
     *
     * @return list<array{index: int, start: int, end: int, length: int}>
     */
    public function chunks(): array
    {
        $chunks = [];

        for ($index = 0; $index < $this->totalChunkCount; $index++) {
            $start = $index * $this->chunkSize;
            $end = $index === $this->totalChunkCount - 1
                ? $this->videoSize - 1
                : $start + $this->chunkSize - 1;

            $chunks[] = ['index' => $index, 'start' => $start, 'end' => $end, 'length' => $end - $start + 1];
        }

        return $chunks;
    }

    /**
     * @return array{source: string, video_size: int, chunk_size: int, total_chunk_count: int}
     */
    public function toSourceInfo(): array
    {
        return [
            'source' => 'FILE_UPLOAD',
            'video_size' => $this->videoSize,
            'chunk_size' => $this->chunkSize,
            'total_chunk_count' => $this->totalChunkCount,
        ];
    }
}
