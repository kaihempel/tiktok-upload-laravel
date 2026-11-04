<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use KaiHempel\TikTokUpload\Contracts\PlatformDriver;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Data\PublishRequest;
use KaiHempel\TikTokUpload\Data\PublishState;
use KaiHempel\TikTokUpload\Drivers\TikTokDriver;
use KaiHempel\TikTokUpload\Facades\TikTok;
use KaiHempel\TikTokUpload\Media\MediaValidator;
use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use KaiHempel\TikTokUpload\Media\VideoFile;
use KaiHempel\TikTokUpload\OAuth\OAuthService;
use KaiHempel\TikTokUpload\Repositories\EloquentTokenRepository;
use KaiHempel\TikTokUpload\TikTokAccountClient;
use KaiHempel\TikTokUpload\TikTokManager;

it('resolves the package services', function (): void {
    expect(app(TokenRepository::class))->toBeInstanceOf(EloquentTokenRepository::class)
        ->and(app(MediaUrlProvider::class))->toBeInstanceOf(SignedRouteUrlProvider::class)
        ->and(app('tiktok-upload'))->toBeInstanceOf(TikTokManager::class)
        ->and(TikTok::oauth())->toBeInstanceOf(OAuthService::class)
        ->and(TikTok::validator())->toBeInstanceOf(MediaValidator::class)
        ->and(TikTok::driver())->toBeInstanceOf(PlatformDriver::class)
        ->and(TikTok::forAccount('abc'))->toBeInstanceOf(TikTokAccountClient::class)
        ->and(TikTok::forAccount('abc')->accountId)->toBe('abc');
});

it('registers the routes', function (): void {
    expect(route('tiktok-upload.connect', absolute: false))->toBe('/tiktok/connect')
        ->and(route('tiktok-upload.callback', absolute: false))->toBe('/tiktok/callback')
        ->and(route('tiktok-upload.disconnect', ['id' => 'x'], false))->toBe('/tiktok/accounts/x/disconnect');
});

it('allows a custom token repository', function (): void {
    config()->set('tiktok-upload.token_repository', CustomTokenRepository::class);

    expect(app(TokenRepository::class))->toBeInstanceOf(CustomTokenRepository::class);
});

it('publishes a video through the platform driver', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clip.mp4', 'video');
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/inbox/video/init/' => Http::response(tiktokFixture('video_init_file')),
        'open-upload.tiktokapis.com/*' => Http::response('', 201),
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tiktokFixture('status_send_to_user_inbox')),
    ]);

    $account = createAccount();
    $driver = app(TikTokDriver::class);

    $result = $driver->publish(new PublishRequest($account->id, VideoFile::fromDisk('local', 'clip.mp4'), 'Caption #tag', 'target-1'));

    expect($result->remoteId)->toBe($account->id.':v_inbox_file~v2.123456789')
        ->and($result->state)->toBe(PublishState::Pending)
        ->and($result->platformStatus)->toBe('PROCESSING_UPLOAD')
        ->and($result->captionForUser)->toBe('Caption #tag')
        ->and($result->reference)->toBe('target-1')
        ->and($result->requiresUserAction)->toBeTrue();

    $status = $driver->status($result->remoteId);

    expect($status->state)->toBe(PublishState::Draft)
        ->and($status->platformStatus)->toBe('SEND_TO_USER_INBOX')
        ->and($status->requiresUserAction)->toBeTrue();
});

it('uses the caption as photo description in the driver', function (): void {
    Http::fake(['open.tiktokapis.com/v2/post/publish/content/init/' => Http::response(tiktokFixture('photo_init'))]);

    $result = app(TikTokDriver::class)->publish(new PublishRequest(
        createAccount()->id,
        PhotoSet::fromUrls(['https://media.example.de/a.jpg']),
        caption: 'Ein ruhiger Morgen #herbst',
        options: ['title' => 'Herbst am See'],
    ));

    expect($result->captionForUser)->toBeNull();

    Http::assertSent(fn ($request): bool => $request['post_info'] === [
        'title' => 'Herbst am See',
        'description' => 'Ein ruhiger Morgen #herbst',
    ]);
});

it('reports failures through the driver status', function (): void {
    Http::fake(['open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tiktokFixture('status_failed'))]);

    $account = createAccount();
    $status = app(TikTokDriver::class)->status(TikTokDriver::remoteId($account->id, 'pub'));

    expect($status->state)->toBe(PublishState::Failed)
        ->and($status->error)->toBe('file_format_check_failed')
        ->and($status->requiresUserAction)->toBeFalse();
});

class CustomTokenRepository extends EloquentTokenRepository {}
