<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Kuweh\TikTokUpload\Contracts\MediaProbe;
use Kuweh\TikTokUpload\Data\ProbeResult;
use Kuweh\TikTokUpload\Exceptions\MediaValidationException;
use Kuweh\TikTokUpload\Media\MediaValidator;
use Kuweh\TikTokUpload\Media\PhotoSet;
use Kuweh\TikTokUpload\Media\VideoFile;

beforeEach(fn () => Storage::fake('local'));

it('accepts supported video containers', function (string $file): void {
    Storage::disk('local')->put($file, 'data');

    expect(app(MediaValidator::class)->videoViolations(VideoFile::fromDisk('local', $file)))->toBe([]);
})->with(['clip.mp4', 'clip.webm', 'clip.MOV']);

it('rejects unsupported extensions, empty and missing files', function (): void {
    $validator = app(MediaValidator::class);
    Storage::disk('local')->put('clip.avi', 'data');
    Storage::disk('local')->put('empty.mp4', '');

    expect($validator->videoViolations(VideoFile::fromDisk('local', 'clip.avi'))[0])->toContain('[avi] is not supported')
        ->and($validator->videoViolations(VideoFile::fromDisk('local', 'empty.mp4')))->toBe(['The video is empty.'])
        ->and($validator->videoViolations(VideoFile::fromDisk('local', 'missing.mp4'))[0])->toContain('does not exist');
});

it('rejects videos larger than the configured maximum', function (): void {
    config()->set('tiktok-upload.video.max_size_bytes', 10);
    Storage::disk('local')->put('clip.mp4', str_repeat('x', 11));

    expect(fn () => app(MediaValidator::class)->validateVideo(VideoFile::fromDisk('local', 'clip.mp4')))
        ->toThrow(MediaValidationException::class, 'the maximum is');
});

it('checks codec, frame rate, duration and resolution with a probe', function (): void {
    config()->set('tiktok-upload.probe.enabled', true);
    app()->instance(MediaProbe::class, new class implements MediaProbe
    {
        public function probe(string $disk, string $path): ProbeResult
        {
            return new ProbeResult(codec: 'mpeg4', fps: 15.0, durationSeconds: 900.0, width: 200, height: 200);
        }
    });
    Storage::disk('local')->put('clip.mp4', 'data');

    $violations = app(MediaValidator::class)->videoViolations(VideoFile::fromDisk('local', 'clip.mp4'));

    expect($violations)->toHaveCount(4)
        ->and(implode(' ', $violations))
        ->toContain('codec [mpeg4]')
        ->toContain('15.00 fps')
        ->toContain('900.0 s')
        ->toContain('200x200');
});

it('accepts probe results within the limits', function (): void {
    $validator = new MediaValidator(config('tiktok-upload.video'), config('tiktok-upload.photo'), new class implements MediaProbe
    {
        public function probe(string $disk, string $path): ProbeResult
        {
            return new ProbeResult(codec: 'h264', fps: 29.97, durationSeconds: 42.0, width: 1080, height: 1920);
        }
    });
    Storage::disk('local')->put('clip.mp4', 'data');

    expect($validator->videoViolations(VideoFile::fromDisk('local', 'clip.mp4')))->toBe([]);
});

it('reports probe failures as violations', function (): void {
    $validator = new MediaValidator([], [], new class implements MediaProbe
    {
        public function probe(string $disk, string $path): ProbeResult
        {
            throw new RuntimeException('ffprobe not installed');
        }
    });
    Storage::disk('local')->put('clip.mp4', 'data');

    expect($validator->videoViolations(VideoFile::fromDisk('local', 'clip.mp4')))
        ->toBe(['The video could not be analysed: ffprobe not installed']);
});

it('validates remote videos by extension only', function (): void {
    $validator = app(MediaValidator::class);

    expect($validator->videoViolations(VideoFile::fromUrl('https://cdn.example.de/v.mp4')))->toBe([])
        ->and($validator->videoViolations(VideoFile::fromUrl('https://cdn.example.de/stream')))->toBe([])
        ->and($validator->videoViolations(VideoFile::fromUrl('https://cdn.example.de/v.flv')))->toHaveCount(1);
});

it('limits the number of photos', function (): void {
    config()->set('tiktok-upload.photo.max_photos', 2);

    $violations = app(MediaValidator::class)->photoViolations(
        PhotoSet::fromUrls(['https://a.de/1.jpg', 'https://a.de/2.jpg', 'https://a.de/3.jpg']),
    );

    expect($violations)->toBe(['A post may contain at most 2 photos, 3 given.']);
});

it('requires at least one photo', function (): void {
    expect(app(MediaValidator::class)->photoViolations(PhotoSet::fromUrls([])))
        ->toBe(['At least one photo is required.']);
});

it('rejects oversized photos', function (): void {
    config()->set('tiktok-upload.photo.max_size_bytes', 10);
    Storage::disk('local')->put('a.jpg', str_repeat('x', 11));

    expect(app(MediaValidator::class)->photoViolations(PhotoSet::fromDisk('local', ['a.jpg']))[0])
        ->toContain('the maximum is');
});

it('rejects files that are not images', function (): void {
    Storage::disk('local')->put('a.jpg', 'not an image');

    expect(app(MediaValidator::class)->photoViolations(PhotoSet::fromDisk('local', ['a.jpg']))[0])
        ->toContain('not a readable image');
});
