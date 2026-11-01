<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kuweh\TikTokUpload\Contracts\TokenRepository;
use Kuweh\TikTokUpload\Events\TikTokAccountConnected;
use Kuweh\TikTokUpload\Events\TikTokAccountDisconnected;
use Kuweh\TikTokUpload\Http\Controllers\OAuthController;
use Kuweh\TikTokUpload\Models\TikTokAccount;
use Kuweh\TikTokUpload\OAuth\Pkce;
use Kuweh\TikTokUpload\TikTokManager;

beforeEach(function (): void {
    Event::fake([TikTokAccountConnected::class, TikTokAccountDisconnected::class]);
});

it('redirects to TikTok with state and scopes', function (): void {
    $response = $this->actingAs(createUser())->get('/tiktok/connect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $session = session(OAuthController::SESSION_KEY);

    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize/?')
        ->and($query['client_key'])->toBe('test-client-key')
        ->and($query['scope'])->toBe('user.info.basic,video.upload')
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe('https://app.example.de/tiktok/callback')
        ->and($query['state'])->toBe($session['state'])
        ->and($query)->not->toHaveKey('code_challenge')
        ->and($session['code_verifier'])->toBeNull();
});

it('adds a PKCE challenge when enabled', function (): void {
    config()->set('tiktok-upload.pkce.enabled', true);

    $location = $this->actingAs(createUser())->get('/tiktok/connect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $verifier = session(OAuthController::SESSION_KEY)['code_verifier'];

    expect($query['code_challenge'])->toBe(Pkce::challengeFor($verifier, 'hex'))
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('completes the OAuth flow and stores the account encrypted', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token')),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(tiktokFixture('user_info')),
    ]);

    $user = createUser();

    $this->actingAs($user)
        ->withSession([OAuthController::SESSION_KEY => ['state' => 'expected-state', 'code_verifier' => 'verifier-123']])
        ->get('/tiktok/callback?code=auth-code&state=expected-state')
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('tiktok-upload.connected');

    $account = TikTokAccount::query()->sole();

    expect($account->open_id)->toBe('-000OqnTqvyAs6PXm0P1Kpv_mZr0FbM4oQJT')
        ->and($account->display_name)->toBe('Kai am See')
        ->and($account->avatar_url)->toContain('tiktokcdn')
        ->and($account->scopes)->toBe(['user.info.basic', 'video.upload'])
        ->and($account->access_token)->toStartWith('act.')
        ->and($account->owner->is($user))->toBeTrue();

    $raw = DB::table('tiktok_accounts')->first();
    expect($raw->access_token)->not->toContain('act.')
        ->and($raw->refresh_token)->not->toContain('rft.');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/token/'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['client_secret'] === 'test-client-secret'
        && $request['code_verifier'] === 'verifier-123'
        && $request->isForm());

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request->hasHeader('Authorization', 'Bearer '.tiktokFixture('token')['access_token']));

    Event::assertDispatched(TikTokAccountConnected::class, fn ($e): bool => $e->accountId === $account->id
        && $e->openId === $account->open_id);
});

it('rejects callbacks with an invalid state', function (?string $stored): void {
    Http::fake();

    $this->actingAs(createUser())
        ->withSession($stored === null ? [] : [OAuthController::SESSION_KEY => ['state' => $stored, 'code_verifier' => null]])
        ->get('/tiktok/callback?code=auth-code&state=attacker')
        ->assertForbidden();

    Http::assertNothingSent();
    expect(TikTokAccount::query()->count())->toBe(0);
})->with(['other-state', null]);

it('handles a denied authorization', function (): void {
    Http::fake();

    $this->actingAs(createUser())
        ->withSession([OAuthController::SESSION_KEY => ['state' => 's', 'code_verifier' => null]])
        ->get('/tiktok/callback?error=access_denied&error_description=User+cancelled&state=s')
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('tiktok-upload.error', 'User cancelled');

    Http::assertNothingSent();
});

it('reports a failed code exchange', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token_error'), 400)]);

    $this->actingAs(createUser())
        ->withSession([OAuthController::SESSION_KEY => ['state' => 's', 'code_verifier' => null]])
        ->get('/tiktok/callback?code=expired&state=s')
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('tiktok-upload.error', 'Authorization code is expired.');

    expect(TikTokAccount::query()->count())->toBe(0);
    Event::assertNotDispatched(TikTokAccountConnected::class);
});

it('still connects when the profile cannot be loaded', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token')),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(tiktokFixture('error_scope_not_authorized'), 401),
    ]);

    $account = app(TikTokManager::class)->oauth()->connect('code', createUser());

    expect($account->displayName)->toBeNull();
    Event::assertDispatched(TikTokAccountConnected::class);
});

it('updates the existing record when an account is connected again', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token')),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(tiktokFixture('user_info')),
    ]);

    $user = createUser();
    $first = app(TikTokManager::class)->oauth()->connect('code', $user);
    app(TokenRepository::class)->markRevoked($first->id);

    $second = app(TikTokManager::class)->oauth()->connect('code', $user);

    expect($second->id)->toBe($first->id)
        ->and($second->isRevoked())->toBeFalse()
        ->and(TikTokAccount::query()->count())->toBe(1);
});

it('revokes the token on disconnect', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/revoke/' => Http::response('')]);

    $user = createUser();
    $account = createAccount($user);

    $this->actingAs($user)
        ->post("/tiktok/accounts/{$account->id}/disconnect")
        ->assertRedirect('/settings/accounts')
        ->assertSessionHas('tiktok-upload.disconnected', $account->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/'
        && $request['token'] === 'act.valid-token'
        && $request['client_key'] === 'test-client-key');

    expect(app(TokenRepository::class)->find($account->id)->isRevoked())->toBeTrue();
    Event::assertDispatched(TikTokAccountDisconnected::class, fn ($e): bool => $e->accountId === $account->id);
});

it('deletes all data on disconnect when requested (GDPR)', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/revoke/' => Http::response('')]);

    $user = createUser();
    $account = createAccount($user);

    $this->actingAs($user)->post("/tiktok/accounts/{$account->id}/disconnect", ['delete' => 1])->assertRedirect();

    expect(TikTokAccount::query()->count())->toBe(0);
});

it('marks the account revoked even if the token is already invalid at TikTok', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/revoke/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token invalid'], 400)]);

    $account = createAccount();
    app(TikTokManager::class)->forAccount($account->id)->disconnect();

    expect(app(TokenRepository::class)->find($account->id)->isRevoked())->toBeTrue();
});

it('forbids disconnecting accounts of other owners', function (): void {
    Http::fake();
    $account = createAccount(createUser('owner@example.de'));

    $this->actingAs(createUser('intruder@example.de'))
        ->post("/tiktok/accounts/{$account->id}/disconnect")
        ->assertForbidden();

    Http::assertNothingSent();
});

it('returns 404 for unknown accounts', function (): void {
    $this->actingAs(createUser())->post('/tiktok/accounts/unknown/disconnect')->assertNotFound();
});

it('allows a custom owner resolver', function (): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token')),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(tiktokFixture('user_info')),
    ]);

    $team = createUser('team@example.de');
    TikTokManager::resolveOwnerUsing(fn () => $team);

    $this->actingAs(createUser())
        ->withSession([OAuthController::SESSION_KEY => ['state' => 's', 'code_verifier' => null]])
        ->get('/tiktok/callback?code=c&state=s')
        ->assertRedirect();

    expect(TikTokAccount::query()->sole()->owner->is($team))->toBeTrue();
});
