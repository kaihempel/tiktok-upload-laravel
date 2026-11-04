<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Storage;
use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use KaiHempel\TikTokUpload\Media\UrlProviders\DiskUrlProvider;
use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/a.jpg', 'jpeg-bytes');
});

function localUrl(string $url): string
{
    // TikTok reaches the app via the verified media domain; locally we call the path.
    return (string) preg_replace('~^https://media\.example\.de~', '', $url);
}

it('serves files through a signed URL without authentication', function (): void {
    $url = app(MediaUrlProvider::class)->urlFor('public', 'media/a.jpg');

    expect($url)->toStartWith('https://media.example.de/tiktok/media/');

    $response = $this->get(localUrl($url));

    $response->assertOk();
    expect($response->streamedContent())->toBe('jpeg-bytes')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('rejects manipulated signatures', function (): void {
    $url = app(SignedRouteUrlProvider::class)->urlFor('public', 'media/a.jpg');

    $this->get(localUrl($url).'0')->assertForbidden();
});

it('rejects expired URLs', function (): void {
    $url = app(SignedRouteUrlProvider::class)->urlFor('public', 'media/a.jpg');

    $this->travel(61)->minutes();

    $this->get(localUrl($url))->assertForbidden();
});

it('rejects revoked URLs', function (): void {
    $provider = app(SignedRouteUrlProvider::class);
    $url = $provider->urlFor('public', 'media/a.jpg');

    $provider->revoke($url);

    $this->get(localUrl($url))->assertStatus(410);
});

it('does not leak the disk path in the URL', function (): void {
    $url = app(SignedRouteUrlProvider::class)->urlFor('public', 'media/a.jpg');

    expect($url)->not->toContain('media/a.jpg')->not->toContain('public');
});

it('returns 404 for missing files', function (): void {
    $url = app(SignedRouteUrlProvider::class)->urlFor('public', 'media/missing.jpg');

    $this->get(localUrl($url))->assertNotFound();
});

it('uses the app URL when no base URL is configured', function (): void {
    config()->set('tiktok-upload.media_url.base_url', null);
    config()->set('app.url', 'https://app.example.de');
    url()->forceRootUrl('https://app.example.de');
    url()->forceScheme('https');

    expect(app(SignedRouteUrlProvider::class)->urlFor('public', 'media/a.jpg'))
        ->toStartWith('https://app.example.de/tiktok/media/');
});

it('can use the disk provider for temporary URLs', function (): void {
    config()->set('tiktok-upload.media_url.provider', DiskUrlProvider::class);

    expect(app(MediaUrlProvider::class))->toBeInstanceOf(DiskUrlProvider::class)
        ->and(app(MediaUrlProvider::class)->urlFor('public', 'media/a.jpg'))->toContain('media/a.jpg');
});

it('keeps the media route when the OAuth routes are disabled', function (): void {
    config()->set('tiktok-upload.routes.enabled', false);
    app('router')->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/tiktok-upload.php';
    app('router')->getRoutes()->refreshNameLookups();

    expect(app('router')->has('tiktok-upload.media'))->toBeTrue()
        ->and(app('router')->has('tiktok-upload.connect'))->toBeFalse();
});
