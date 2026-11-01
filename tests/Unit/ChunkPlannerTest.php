<?php

declare(strict_types=1);

use Kuweh\TikTokUpload\Upload\ChunkPlanner;

const MB = 1024 * 1024;

it('uploads files below the minimum chunk size as a single chunk', function (): void {
    $plan = (new ChunkPlanner(10 * MB))->plan(3 * MB);

    expect($plan->chunkSize)->toBe(3 * MB)
        ->and($plan->totalChunkCount)->toBe(1)
        ->and($plan->chunks())->toBe([['index' => 0, 'start' => 0, 'end' => 3 * MB - 1, 'length' => 3 * MB]]);
});

it('uploads files that fit into one chunk as a whole', function (): void {
    $plan = (new ChunkPlanner(10 * MB))->plan(8 * MB);

    expect($plan->chunkSize)->toBe(8 * MB)->and($plan->totalChunkCount)->toBe(1);
});

it('splits exact multiples into equally sized chunks', function (): void {
    $plan = (new ChunkPlanner(10 * MB))->plan(50 * MB);

    expect($plan->chunkSize)->toBe(10 * MB)
        ->and($plan->totalChunkCount)->toBe(5)
        ->and($plan->toSourceInfo())->toBe([
            'source' => 'FILE_UPLOAD',
            'video_size' => 50 * MB,
            'chunk_size' => 10 * MB,
            'total_chunk_count' => 5,
        ]);

    foreach ($plan->chunks() as $chunk) {
        expect($chunk['length'])->toBe(10 * MB);
    }
});

it('lets the last chunk take a large remainder', function (): void {
    $size = 59 * MB + 12345;
    $plan = (new ChunkPlanner(10 * MB))->plan($size);
    $chunks = $plan->chunks();

    expect($plan->totalChunkCount)->toBe(5)
        ->and($chunks)->toHaveCount(5)
        ->and($chunks[4]['start'])->toBe(40 * MB)
        ->and($chunks[4]['end'])->toBe($size - 1)
        ->and($chunks[4]['length'])->toBe(19 * MB + 12345)
        ->and(array_sum(array_column($chunks, 'length')))->toBe($size);
});

it('uses a single chunk when only one chunk would result', function (): void {
    $plan = (new ChunkPlanner(10 * MB))->plan(15 * MB);

    expect($plan->totalChunkCount)->toBe(1)->and($plan->chunkSize)->toBe(15 * MB);
});

it('clamps the configured chunk size to TikTok limits', function (int $preferred, int $expected): void {
    $plan = (new ChunkPlanner($preferred))->plan(500 * MB);

    expect($plan->chunkSize)->toBe($expected)
        ->and($plan->totalChunkCount)->toBe(intdiv(500 * MB, $expected));
})->with([
    'too small' => [1 * MB, ChunkPlanner::MIN_CHUNK_SIZE],
    'too large' => [200 * MB, ChunkPlanner::MAX_CHUNK_SIZE],
    'in range' => [20 * MB, 20 * MB],
]);

it('produces contiguous byte ranges', function (): void {
    $plan = (new ChunkPlanner(5 * MB))->plan(123 * MB + 7);
    $expectedStart = 0;

    foreach ($plan->chunks() as $chunk) {
        expect($chunk['start'])->toBe($expectedStart);
        $expectedStart = $chunk['end'] + 1;
    }

    expect($expectedStart)->toBe(123 * MB + 7);
});

it('rejects empty files', function (): void {
    (new ChunkPlanner)->plan(0);
})->throws(InvalidArgumentException::class);
