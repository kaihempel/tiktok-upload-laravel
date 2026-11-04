<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Events\TikTokAccountReauthorizationRequired;
use KaiHempel\TikTokUpload\Events\TikTokTokenRefreshed;
use KaiHempel\TikTokUpload\Exceptions\AuthenticationException;
use KaiHempel\TikTokUpload\Facades\TikTok;

beforeEach(function (): void {
    Event::fake([TikTokTokenRefreshed::class, TikTokAccountReauthorizationRequired::class]);
});

it('refreshes the token before a request when it expires soon', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token_refreshed')),
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tiktokFixture('status_processing_upload')),
    ]);

    $account = createAccount(expiresInMinutes: 10);

    TikTok::forAccount($account->id)->status('pub-1');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'rft.refresh-token',
        fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer act.refreshed-access-token'),
    ]);

    $stored = app(TokenRepository::class)->find($account->id);

    expect($stored->accessToken)->toBe('act.refreshed-access-token')
        ->and($stored->refreshToken)->toBe('rft.rotated-refresh-token')
        ->and($stored->accessTokenExpiresAt->isAfter(now()->addHours(23)))->toBeTrue();

    Event::assertDispatched(TikTokTokenRefreshed::class, fn ($e): bool => $e->accountId === $account->id);
});

it('does not refresh tokens that are still valid long enough', function (): void {
    Http::fake(['open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tiktokFixture('status_processing_upload'))]);

    TikTok::forAccount(createAccount(expiresInMinutes: 120)->id)->status('pub-1');

    Http::assertSentCount(1);
    Event::assertNotDispatched(TikTokTokenRefreshed::class);
});

it('refreshes once and retries when TikTok rejects the access token', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token_refreshed')),
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::sequence()
            ->push(tiktokFixture('error_access_token_invalid'), 401)
            ->push(tiktokFixture('status_send_to_user_inbox')),
    ]);

    $status = TikTok::forAccount(createAccount()->id)->status('pub-1');

    expect($status->status->value)->toBe('SEND_TO_USER_INBOX');
    Http::assertSentCount(3);
});

it('requires re-authorization when the refresh fails', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.', 'log_id' => 'L1'], 400)]);

    $account = createAccount(expiresInMinutes: 5);

    expect(fn () => TikTok::forAccount($account->id)->status('pub-1'))
        ->toThrow(AuthenticationException::class, 'Refresh token is invalid or expired.');

    Event::assertDispatched(TikTokAccountReauthorizationRequired::class, fn ($e): bool => $e->accountId === $account->id
        && $e->reason === 'invalid_grant');
});

it('requires re-authorization without calling TikTok when the refresh token expired', function (): void {
    Http::fake();

    $account = createAccount(expiresInMinutes: 5, refreshExpiresInMinutes: -1);

    expect(fn () => TikTok::forAccount($account->id)->refreshToken())->toThrow(AuthenticationException::class);

    Http::assertNothingSent();
    Event::assertDispatched(TikTokAccountReauthorizationRequired::class, fn ($e): bool => $e->reason === 'refresh_token_expired');
});

it('refuses requests for revoked or unknown accounts', function (): void {
    Http::fake();
    $account = createAccount();
    app(TokenRepository::class)->markRevoked($account->id);

    expect(fn () => TikTok::forAccount($account->id)->status('p'))->toThrow(AuthenticationException::class, 'revoked')
        ->and(fn () => TikTok::forAccount('missing')->status('p'))->toThrow(AuthenticationException::class, 'does not exist');

    Http::assertNothingSent();
});

it('refreshes expiring tokens with the artisan command', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token_refreshed'))]);

    $expiring = createAccount(expiresInMinutes: 30, openId: 'expiring');
    $fresh = createAccount(createUser('other@example.de'), expiresInMinutes: 60 * 20, openId: 'fresh');
    $revoked = createAccount(createUser('third@example.de'), expiresInMinutes: 5, openId: 'revoked');
    app(TokenRepository::class)->markRevoked($revoked->id);

    $this->artisan('tiktok:refresh-tokens')
        ->expectsOutputToContain('Refreshed 1 account(s), 0 failure(s).')
        ->assertSuccessful();

    expect(app(TokenRepository::class)->find($expiring->id)->accessToken)->toBe('act.refreshed-access-token')
        ->and(app(TokenRepository::class)->find($fresh->id)->accessToken)->toBe('act.valid-token');

    Http::assertSentCount(1);
});

it('reports failures in the artisan command', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant'], 400)]);

    createAccount(expiresInMinutes: 5);

    $this->artisan('tiktok:refresh-tokens', ['--minutes' => 10])
        ->expectsOutputToContain('0 account(s), 1 failure(s)')
        ->assertFailed();
});
