<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use KaiHempel\TikTokUpload\Contracts\MediaTransformer;
use KaiHempel\TikTokUpload\Data\MediaType;
use KaiHempel\TikTokUpload\Data\PublishStatus;
use KaiHempel\TikTokUpload\Events\TikTokUploadInitiated;
use KaiHempel\TikTokUpload\Exceptions\MediaValidationException;
use KaiHempel\TikTokUpload\Exceptions\PendingShareLimitReachedException;
use KaiHempel\TikTokUpload\Exceptions\UploadFailedException;
use KaiHempel\TikTokUpload\Facades\TikTok;
use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use KaiHempel\TikTokUpload\Media\VideoFile;
use KaiHempel\TikTokUpload\Support\PendingShareTracker;

const INIT_URL = 'open.tiktokapis.com/v2/post/publish/inbox/video/init/';
const UPLOAD_URL = 'open-upload.tiktokapis.com/*';

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Event::fake([TikTokUploadInitiated::class]);
});

function putVideo(string $path, int $bytes): string
{
    // Deterministic content so chunk bodies can be compared.
    $content = substr(str_repeat(implode('', range('a', 'z')), intdiv($bytes, 26) + 1), 0, $bytes);
    Storage::disk('local')->put($path, $content);

    return $content;
}

it('uploads a small video as one chunk (FILE_UPLOAD)', function (): void {
    Http::fake([
        INIT_URL => Http::response(tiktokFixture('video_init_file')),
        UPLOAD_URL => Http::response('', 201),
    ]);

    $content = putVideo('videos/clip.mp4', 1024);
    $account = createAccount();

    $result = TikTok::forAccount($account->id)->uploadVideo(
        VideoFile::fromDisk('local', 'videos/clip.mp4'),
        source: 'file',
        reference: 'post-target-1',
        caption: 'Ein ruhiger Morgen #herbst',
    );

    expect($result->publishId)->toBe('v_inbox_file~v2.123456789')
        ->and($result->status)->toBe(PublishStatus::ProcessingUpload)
        ->and($result->accountId)->toBe($account->id)
        ->and($result->reference)->toBe('post-target-1')
        ->and($result->captionForUser)->toBe('Ein ruhiger Morgen #herbst')
        ->and($result->mediaType)->toBe(MediaType::Video);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'inbox/video/init')
        && $request->hasHeader('Authorization', 'Bearer act.valid-token')
        && $request->data() === ['source_info' => [
            'source' => 'FILE_UPLOAD',
            'video_size' => 1024,
            'chunk_size' => 1024,
            'total_chunk_count' => 1,
        ]]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === tiktokFixture('video_init_file')['data']['upload_url']
        && $request->header('Content-Range')[0] === 'bytes 0-1023/1024'
        && $request->header('Content-Type')[0] === 'video/mp4'
        && $request->header('Content-Length')[0] === '1024'
        && ! $request->hasHeader('Authorization')
        && $request->body() === $content);

    Event::assertDispatched(TikTokUploadInitiated::class, fn ($e): bool => $e->publishId === 'v_inbox_file~v2.123456789'
        && $e->reference === 'post-target-1'
        && $e->mediaType === MediaType::Video);

    expect(app(PendingShareTracker::class)->count($account->id))->toBe(1);
});

it('streams large videos in chunks with Content-Range headers', function (): void {
    config()->set('tiktok-upload.upload.chunk_size', 5 * 1024 * 1024);

    Http::fake([
        INIT_URL => Http::response(tiktokFixture('video_init_file')),
        UPLOAD_URL => Http::sequence()->push('', 206)->push('', 201),
    ]);

    $size = 12 * 1024 * 1024 + 100;
    $content = putVideo('big.mov', $size);

    TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'big.mov'));

    $puts = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $r): bool => $r->method() === 'PUT')->values();

    expect($puts)->toHaveCount(2)
        ->and($puts[0]->header('Content-Range')[0])->toBe('bytes 0-5242879/'.$size)
        ->and($puts[0]->header('Content-Type')[0])->toBe('video/quicktime')
        ->and($puts[0]->body())->toBe(substr($content, 0, 5242880))
        ->and($puts[1]->header('Content-Range')[0])->toBe('bytes 5242880-'.($size - 1).'/'.$size)
        ->and(strlen($puts[1]->body()))->toBe($size - 5242880);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'init')
        && $request['source_info']['total_chunk_count'] === 2
        && $request['source_info']['chunk_size'] === 5242880);
});

it('retries a failed chunk with backoff', function (): void {
    Http::fake([
        INIT_URL => Http::response(tiktokFixture('video_init_file')),
        UPLOAD_URL => Http::sequence()->push('', 500)->push('', 503)->push('', 201),
    ]);

    putVideo('clip.mp4', 2048);

    TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'));

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT'))->toHaveCount(3);
});

it('gives up after three chunk retries', function (): void {
    Http::fake([
        INIT_URL => Http::response(tiktokFixture('video_init_file')),
        UPLOAD_URL => Http::response('', 500),
    ]);

    putVideo('clip.mp4', 2048);

    try {
        TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'));
        $this->fail('Expected exception');
    } catch (UploadFailedException $e) {
        expect($e->errorCode)->toBe('chunk_upload_failed')
            ->and($e->isRetryable())->toBeTrue();
    }

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT'))->toHaveCount(4);
});

it('does not retry chunks rejected with a client error', function (): void {
    Http::fake([
        INIT_URL => Http::response(tiktokFixture('video_init_file')),
        UPLOAD_URL => Http::response('', 400),
    ]);

    putVideo('clip.mp4', 2048);

    expect(fn () => TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4')))
        ->toThrow(fn (UploadFailedException $e) => expect($e->isRetryable())->toBeFalse());
});

it('re-initialises the upload when the upload URL expired', function (): void {
    Http::fake([
        INIT_URL => Http::sequence()
            ->push(tiktokFixture('video_init_file'))
            ->push(['data' => ['publish_id' => 'v_inbox_file~second', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=2'], 'error' => ['code' => 'ok']]),
        UPLOAD_URL => Http::sequence()->push('', 403)->push('', 201),
    ]);

    putVideo('clip.mp4', 2048);

    $result = TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'));

    expect($result->publishId)->toBe('v_inbox_file~second');
});

it('uploads via PULL_FROM_URL with a signed URL on the verified domain', function (): void {
    Http::fake([INIT_URL => Http::response(tiktokFixture('video_init_url'))]);
    putVideo('clip.mp4', 2048);

    $result = TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'), source: 'url');

    expect($result->status)->toBe(PublishStatus::ProcessingDownload)
        ->and($result->mediaUrls)->toHaveCount(1)
        ->and($result->mediaUrls[0])->toStartWith('https://media.example.de/tiktok/media/')
        ->and($result->mediaUrls[0])->toContain('.mp4?expires=')
        ->and(app(SignedRouteUrlProvider::class)->decodeUrl($result->mediaUrls[0]))->toMatchArray(['disk' => 'local', 'path' => 'clip.mp4']);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $result->mediaUrls[0]],
    ]);
    Http::assertSentCount(1);
});

it('uses the configured default source', function (): void {
    config()->set('tiktok-upload.upload.default_source', 'url');
    Http::fake([INIT_URL => Http::response(tiktokFixture('video_init_url'))]);
    putVideo('clip.mp4', 2048);

    $result = TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'));

    expect($result->status)->toBe(PublishStatus::ProcessingDownload);
});

it('uploads remote videos by URL', function (): void {
    Http::fake([INIT_URL => Http::response(tiktokFixture('video_init_url'))]);

    TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromUrl('https://media.example.de/clip.mp4'), source: 'file');

    Http::assertSent(fn (Request $request): bool => $request['source_info']['video_url'] === 'https://media.example.de/clip.mp4');
});

it('validates the video before sending any request', function (): void {
    Http::fake();
    Storage::disk('local')->put('clip.avi', 'data');

    expect(fn () => TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.avi')))
        ->toThrow(MediaValidationException::class);

    Http::assertNothingSent();
});

it('blocks locally when the pending share limit is reached and blocking is enabled', function (): void {
    config()->set('tiktok-upload.pending_shares.block', true);
    Http::fake();
    putVideo('clip.mp4', 100);
    $account = createAccount();

    foreach (range(1, 5) as $i) {
        app(PendingShareTracker::class)->record($account->id);
    }

    expect(fn () => TikTok::forAccount($account->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4')))
        ->toThrow(PendingShareLimitReachedException::class);

    Http::assertNothingSent();
});

it('only warns about the pending share limit by default', function (): void {
    Http::fake([INIT_URL => Http::response(tiktokFixture('video_init_file')), UPLOAD_URL => Http::response('', 201)]);
    putVideo('clip.mp4', 100);
    $account = createAccount();

    foreach (range(1, 5) as $i) {
        app(PendingShareTracker::class)->record($account->id);
    }

    TikTok::forAccount($account->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4'));

    expect(TikTok::forAccount($account->id)->pendingShareCount())->toBe(6);
});

it('surfaces TikTok\'s pending share limit error', function (): void {
    Http::fake([INIT_URL => Http::response(tiktokFixture('error_spam_risk_too_many_pending_share'), 403)]);
    putVideo('clip.mp4', 100);

    expect(fn () => TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.mp4')))
        ->toThrow(PendingShareLimitReachedException::class);
});

it('applies a configured media transformer before validation', function (): void {
    config()->set('tiktok-upload.transformer', RenamingTransformer::class);
    Http::fake([INIT_URL => Http::response(tiktokFixture('video_init_file')), UPLOAD_URL => Http::response('', 201)]);
    Storage::disk('local')->put('clip.avi', 'raw');
    Storage::disk('local')->put('clip.mp4', 'transcoded');

    TikTok::forAccount(createAccount()->id)->uploadVideo(VideoFile::fromDisk('local', 'clip.avi'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->body() === 'transcoded');
});

class RenamingTransformer implements MediaTransformer
{
    public function transformVideo(VideoFile $video): VideoFile
    {
        return VideoFile::fromDisk((string) $video->disk, str_replace('.avi', '.mp4', (string) $video->path));
    }

    public function transformPhotos(PhotoSet $photos): PhotoSet
    {
        return $photos;
    }
}
