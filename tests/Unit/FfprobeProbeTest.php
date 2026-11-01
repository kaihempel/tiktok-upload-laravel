<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Kuweh\TikTokUpload\Exceptions\TikTokException;
use Kuweh\TikTokUpload\Media\Probe\FfprobeProbe;

it('parses ffprobe output', function (): void {
    $result = FfprobeProbe::parse(json_encode([
        'streams' => [['codec_name' => 'H264', 'width' => 1080, 'height' => 1920, 'avg_frame_rate' => '30000/1001']],
        'format' => ['duration' => '42.500000'],
    ]));

    expect($result->codec)->toBe('h264')
        ->and($result->fps)->toBe(29.97)
        ->and($result->durationSeconds)->toBe(42.5)
        ->and($result->width)->toBe(1080)
        ->and($result->height)->toBe(1920);
});

it('falls back to r_frame_rate', function (): void {
    $result = FfprobeProbe::parse(json_encode([
        'streams' => [['avg_frame_rate' => '0/0', 'r_frame_rate' => '25/1']],
    ]));

    expect($result->fps)->toBe(25.0)->and($result->codec)->toBeNull();
});

it('runs ffprobe for files on a disk', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clip.mp4', 'video');

    Process::fake([
        '*' => Process::result(json_encode([
            'streams' => [['codec_name' => 'vp9', 'width' => 720, 'height' => 1280, 'avg_frame_rate' => '60/1']],
            'format' => ['duration' => '10'],
        ])),
    ]);

    $result = app(FfprobeProbe::class, ['binary' => '/usr/bin/ffprobe'])->probe('local', 'clip.mp4');

    expect($result->codec)->toBe('vp9')->and($result->fps)->toBe(60.0);

    Process::assertRan(fn ($process): bool => $process->command[0] === '/usr/bin/ffprobe'
        && str_ends_with((string) end($process->command), 'clip.mp4'));
});

it('throws when ffprobe fails', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clip.mp4', 'video');
    Process::fake(['*' => Process::result('', 'Invalid data found', 1)]);

    app(FfprobeProbe::class)->probe('local', 'clip.mp4');
})->throws(TikTokException::class, 'ffprobe failed');
