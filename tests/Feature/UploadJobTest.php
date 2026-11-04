<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KaiHempel\TikTokUpload\Events\TikTokUploadFailed;
use KaiHempel\TikTokUpload\Events\TikTokUploadInitiated;
use KaiHempel\TikTokUpload\Exceptions\ServerException;
use KaiHempel\TikTokUpload\Jobs\Middleware\RateLimitedPerTikTokAccount;
use KaiHempel\TikTokUpload\Jobs\PollTikTokPublishStatus;
use KaiHempel\TikTokUpload\Jobs\UploadToTikTokInbox;
use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\VideoFile;
use KaiHempel\TikTokUpload\TikTokManager;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clip.mp4', str_repeat('v', 512));
    Event::fake([TikTokUploadFailed::class, TikTokUploadInitiated::class]);
});

it('is configured for the TikTok queue', function (): void {
    config()->set('tiktok-upload.queue.connection', 'redis');

    $job = new UploadToTikTokInbox('acc', VideoFile::fromDisk('local', 'clip.mp4'), reference: 'ref');

    expect($job->queue)->toBe('tiktok')
        ->and($job->connection)->toBe('redis')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 90, 270])
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimitedPerTikTokAccount::class)
        ->and($job->tikTokAccountId())->toBe('acc');
});

it('uploads and dispatches the delayed status polling', function (): void {
    Queue::fake([PollTikTokPublishStatus::class]);
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/inbox/video/init/' => Http::response(tiktokFixture('video_init_file')),
        'open-upload.tiktokapis.com/*' => Http::response('', 201),
    ]);

    $account = createAccount();

    UploadToTikTokInbox::dispatch($account->id, VideoFile::fromDisk('local', 'clip.mp4'), reference: 'target-1', caption: 'Caption #tag');

    Queue::assertPushed(PollTikTokPublishStatus::class, fn (PollTikTokPublishStatus $job): bool => $job->accountId === $account->id
        && $job->publishId === 'v_inbox_file~v2.123456789'
        && $job->reference === 'target-1'
        && $job->captionForUser === 'Caption #tag'
        && $job->delay === 15
        && $job->queue === 'tiktok');

    Event::assertDispatched(TikTokUploadInitiated::class);
});

it('uploads photo sets', function (): void {
    Queue::fake([PollTikTokPublishStatus::class]);
    Http::fake(['open.tiktokapis.com/v2/post/publish/content/init/' => Http::response(tiktokFixture('photo_init'))]);

    UploadToTikTokInbox::dispatch(createAccount()->id, PhotoSet::fromUrls(['https://media.example.de/a.jpg'])->title('T'));

    Queue::assertPushed(PollTikTokPublishStatus::class, fn (PollTikTokPublishStatus $job): bool => $job->mediaUrls === ['https://media.example.de/a.jpg']);
});

it('fails immediately on non-retryable errors and fires TikTokUploadFailed', function (string $fixture, int $status): void {
    Queue::fake([PollTikTokPublishStatus::class]);
    Http::fake(['open.tiktokapis.com/*' => Http::response(tiktokFixture($fixture), $status)]);

    $job = (new UploadToTikTokInbox(createAccount()->id, VideoFile::fromDisk('local', 'clip.mp4'), reference: 'r-1'))->withFakeQueueInteractions();
    $job->handle(app(TikTokManager::class));

    $job->assertFailed()->assertNotReleased();
    Queue::assertNothingPushed();
})->with([
    ['error_spam_risk_too_many_pending_share', 403],
    ['error_spam_risk_too_many_posts', 403],
    ['error_scope_not_authorized', 401],
    ['error_url_ownership_unverified', 403],
]);

it('fails immediately on validation errors', function (): void {
    Http::fake();
    Storage::disk('local')->put('clip.avi', 'x');

    $job = (new UploadToTikTokInbox(createAccount()->id, VideoFile::fromDisk('local', 'clip.avi')))->withFakeQueueInteractions();
    $job->handle(app(TikTokManager::class));

    $job->assertFailed();
    Http::assertNothingSent();
});

it('dispatches TikTokUploadFailed with error code and reference when failing', function (): void {
    Http::fake(['open.tiktokapis.com/*' => Http::response(tiktokFixture('error_spam_risk_too_many_posts'), 403)]);

    // sync queue: fail() invokes failed()
    UploadToTikTokInbox::dispatch(createAccount()->id, VideoFile::fromDisk('local', 'clip.mp4'), reference: 'r-9');

    Event::assertDispatched(TikTokUploadFailed::class, fn (TikTokUploadFailed $e): bool => $e->reference === 'r-9'
        && $e->errorCode === 'spam_risk_too_many_posts'
        && $e->publishId === null);
});

it('releases the job when TikTok rate limits', function (): void {
    Http::fake(['open.tiktokapis.com/*' => Http::response(tiktokFixture('error_rate_limit_exceeded'), 429, ['Retry-After' => '30'])]);

    $job = (new UploadToTikTokInbox(createAccount()->id, VideoFile::fromDisk('local', 'clip.mp4')))->withFakeQueueInteractions();
    $job->handle(app(TikTokManager::class));

    $job->assertReleased(30)->assertNotFailed();
});

it('rethrows retryable server errors so the queue retries with backoff', function (): void {
    Http::fake(['open.tiktokapis.com/*' => Http::response(tiktokFixture('error_internal_error'), 500)]);

    $job = (new UploadToTikTokInbox(createAccount()->id, VideoFile::fromDisk('local', 'clip.mp4')))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(TikTokManager::class)))->toThrow(ServerException::class);
    $job->assertNotFailed();
});

it('limits jobs to six TikTok requests per minute and account', function (): void {
    $middleware = new RateLimitedPerTikTokAccount;
    $ran = 0;

    foreach (range(1, 7) as $i) {
        $job = (new UploadToTikTokInbox('acc-1', VideoFile::fromDisk('local', 'clip.mp4')))->withFakeQueueInteractions();
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
    }

    expect($ran)->toBe(6);
    $job->assertReleased();

    // other accounts are not affected
    $other = (new UploadToTikTokInbox('acc-2', VideoFile::fromDisk('local', 'clip.mp4')))->withFakeQueueInteractions();
    $middleware->handle($other, function () use (&$ran): void {
        $ran++;
    });

    expect($ran)->toBe(7);

    $this->travel(61)->seconds();
    $middleware->handle($job, function () use (&$ran): void {
        $ran++;
    });

    expect($ran)->toBe(8);
});
