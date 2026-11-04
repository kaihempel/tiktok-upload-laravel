<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use KaiHempel\TikTokUpload\Events\TikTokPostPublished;
use KaiHempel\TikTokUpload\Events\TikTokUploadFailed;
use KaiHempel\TikTokUpload\Events\TikTokUploadSentToInbox;
use KaiHempel\TikTokUpload\Events\TikTokUploadTimedOut;
use KaiHempel\TikTokUpload\Jobs\PollTikTokPublishStatus;
use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use KaiHempel\TikTokUpload\TikTokManager;

const STATUS_URL = 'open.tiktokapis.com/v2/post/publish/status/fetch/';

beforeEach(function (): void {
    Event::fake([TikTokUploadSentToInbox::class, TikTokPostPublished::class, TikTokUploadFailed::class, TikTokUploadTimedOut::class]);
    $this->account = createAccount();
});

function pollJob(string $accountId, int $attempts = 1, bool $longTerm = false, array $mediaUrls = []): PollTikTokPublishStatus
{
    $job = (new PollTikTokPublishStatus($accountId, 'pub-1', 'ref-1', 'Caption #tag', $mediaUrls, $longTerm))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    return $job;
}

it('keeps polling while TikTok is processing', function (string $fixture): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture($fixture))]);

    $job = pollJob($this->account->id, attempts: 3);
    $job->handle(app(TikTokManager::class));

    $job->assertReleased(15);
    Event::assertNothingDispatched();
    Http::assertSent(fn ($request): bool => $request['publish_id'] === 'pub-1');
})->with(['status_processing_upload', 'status_processing_download']);

it('times out after max_attempts', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_processing_upload'))]);

    $job = pollJob($this->account->id, attempts: 80);
    $job->handle(app(TikTokManager::class));

    $job->assertNotReleased();
    Event::assertDispatched(TikTokUploadTimedOut::class, fn ($e): bool => $e->publishId === 'pub-1' && $e->reference === 'ref-1');
});

it('allows more queue tries than polling attempts', function (): void {
    config()->set('tiktok-upload.polling.max_attempts', 20);

    expect((new PollTikTokPublishStatus('a', 'p'))->tries)->toBe(30);
});

it('stops polling when the draft is in the inbox', function (): void {
    Queue::fake();
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_send_to_user_inbox'))]);

    $job = pollJob($this->account->id);
    $job->handle(app(TikTokManager::class));

    $job->assertNotReleased()->assertNotFailed();
    Event::assertDispatched(TikTokUploadSentToInbox::class, fn ($e): bool => $e->accountId === $this->account->id
        && $e->publishId === 'pub-1'
        && $e->reference === 'ref-1'
        && $e->captionForUser === 'Caption #tag');
    Queue::assertNothingPushed();
});

it('starts optional long-term polling after SEND_TO_USER_INBOX', function (): void {
    config()->set('tiktok-upload.polling.long_term.enabled', true);
    Queue::fake();
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_send_to_user_inbox'))]);

    pollJob($this->account->id)->handle(app(TikTokManager::class));

    Queue::assertPushed(PollTikTokPublishStatus::class, fn (PollTikTokPublishStatus $job): bool => $job->longTerm && $job->delay === 3600);
});

it('waits in long-term mode until the user publishes', function (): void {
    Http::fake([STATUS_URL => Http::sequence()
        ->push(tiktokFixture('status_send_to_user_inbox'))
        ->push(tiktokFixture('status_publish_complete'))]);

    $waiting = pollJob($this->account->id, longTerm: true);
    $waiting->handle(app(TikTokManager::class));
    $waiting->assertReleased(3600);
    Event::assertNotDispatched(TikTokUploadSentToInbox::class);

    $published = pollJob($this->account->id, attempts: 2, longTerm: true);
    $published->handle(app(TikTokManager::class));
    $published->assertNotReleased();

    Event::assertDispatched(TikTokPostPublished::class, fn ($e): bool => $e->publicPostIds === ['7291234567890123456']);
});

it('ends long-term polling silently after its max attempts', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_send_to_user_inbox'))]);

    $job = pollJob($this->account->id, attempts: 48, longTerm: true);
    $job->handle(app(TikTokManager::class));

    $job->assertNotReleased();
    Event::assertNothingDispatched();
});

it('reports PUBLISH_COMPLETE', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_publish_complete'))]);

    pollJob($this->account->id)->handle(app(TikTokManager::class));

    Event::assertDispatched(TikTokPostPublished::class);
});

it('reports FAILED with the fail_reason', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_failed'))]);

    $job = pollJob($this->account->id);
    $job->handle(app(TikTokManager::class));

    $job->assertNotReleased();
    Event::assertDispatched(TikTokUploadFailed::class, fn ($e): bool => $e->errorCode === 'file_format_check_failed'
        && $e->publishId === 'pub-1'
        && str_contains($e->message, 'file_format_check_failed'));
});

it('invalidates media URLs after SEND_TO_USER_INBOX when configured', function (): void {
    config()->set('tiktok-upload.media_url.invalidate_after_inbox', true);
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_send_to_user_inbox'))]);

    $provider = app(SignedRouteUrlProvider::class);
    $url = $provider->urlFor('public', 'a.jpg');

    pollJob($this->account->id, mediaUrls: [$url])->handle(app(TikTokManager::class));

    expect($provider->isRevoked($provider->decodeUrl($url)['nonce']))->toBeTrue();
});

it('keeps media URLs valid after SEND_TO_USER_INBOX by default', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('status_send_to_user_inbox'))]);

    $provider = app(SignedRouteUrlProvider::class);
    $url = $provider->urlFor('public', 'a.jpg');

    pollJob($this->account->id, mediaUrls: [$url])->handle(app(TikTokManager::class));

    expect($provider->isRevoked($provider->decodeUrl($url)['nonce']))->toBeFalse();
});

it('releases with retry-after on rate limits', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('error_rate_limit_exceeded'), 429, ['Retry-After' => '45'])]);

    $job = pollJob($this->account->id);
    $job->handle(app(TikTokManager::class));

    $job->assertReleased(45);
});

it('continues polling after transient server errors', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('error_internal_error'), 500)]);

    $job = pollJob($this->account->id);
    $job->handle(app(TikTokManager::class));

    $job->assertReleased(15)->assertNotFailed();
});

it('fails on non-retryable errors without polling forever', function (): void {
    Http::fake([STATUS_URL => Http::response(tiktokFixture('error_scope_not_authorized'), 401)]);

    $job = pollJob($this->account->id);
    $job->handle(app(TikTokManager::class));

    $job->assertFailed()->assertNotReleased();
});

it('fires TikTokUploadFailed from failed()', function (): void {
    (new PollTikTokPublishStatus($this->account->id, 'pub-1', 'ref-1'))->failed(new RuntimeException('boom'));

    Event::assertDispatched(TikTokUploadFailed::class, fn ($e): bool => $e->errorCode === 'job_failed' && $e->publishId === 'pub-1');
});
