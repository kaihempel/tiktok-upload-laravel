<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kuweh\TikTokUpload\Exceptions\AuthenticationException;
use Kuweh\TikTokUpload\Exceptions\DailyPostLimitReachedException;
use Kuweh\TikTokUpload\Exceptions\PendingShareLimitReachedException;
use Kuweh\TikTokUpload\Exceptions\RateLimitException;
use Kuweh\TikTokUpload\Exceptions\ScopeNotAuthorizedException;
use Kuweh\TikTokUpload\Exceptions\ServerException;
use Kuweh\TikTokUpload\Exceptions\TikTokException;
use Kuweh\TikTokUpload\Exceptions\UnexpectedResponseException;
use Kuweh\TikTokUpload\Exceptions\UrlOwnershipUnverifiedException;
use Kuweh\TikTokUpload\Facades\TikTok;

it('maps every TikTok error response onto an exception', function (string $fixture, int $status, string $class): void {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(tiktokFixture('token_refreshed')),
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(tiktokFixture($fixture), $status, ['Retry-After' => '17']),
    ]);

    $body = tiktokFixture($fixture);

    try {
        TikTok::forAccount(createAccount()->id)->status('pub-1');
        $this->fail('No exception thrown.');
    } catch (TikTokException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->errorCode)->toBe($body['error']['code'])
            ->and($e->logId)->toBe($body['error']['log_id'])
            ->and($e->httpStatus)->toBe($status)
            ->and($e->getMessage())->toBe($body['error']['message']);

        if ($e instanceof RateLimitException) {
            expect($e->retryAfter)->toBe(17);
        }
    }
})->with([
    ['error_access_token_invalid', 401, AuthenticationException::class],
    ['error_scope_not_authorized', 401, ScopeNotAuthorizedException::class],
    ['error_rate_limit_exceeded', 429, RateLimitException::class],
    ['error_spam_risk_too_many_pending_share', 403, PendingShareLimitReachedException::class],
    ['error_spam_risk_too_many_posts', 403, DailyPostLimitReachedException::class],
    ['error_url_ownership_unverified', 403, UrlOwnershipUnverifiedException::class],
    ['error_internal_error', 500, ServerException::class],
    ['error_invalid_param', 400, TikTokException::class],
]);

it('maps 5xx responses without a body to server exceptions', function (): void {
    Http::fake(['open.tiktokapis.com/*' => Http::response('Bad Gateway', 502)]);

    expect(fn () => TikTok::forAccount(createAccount()->id)->status('pub'))
        ->toThrow(ServerException::class);
});

it('rejects successful responses without an error object', function (): void {
    Http::fake(['open.tiktokapis.com/*' => Http::response(['data' => []])]);

    expect(fn () => TikTok::forAccount(createAccount()->id)->status('pub'))
        ->toThrow(UnexpectedResponseException::class);
});

it('logs the log_id but never the access token', function (): void {
    config()->set('logging.default', 'null');
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message;
    });

    Http::fake(['open.tiktokapis.com/*' => Http::response(tiktokFixture('error_invalid_param'), 400)]);

    try {
        TikTok::forAccount(createAccount()->id)->status('pub');
    } catch (TikTokException) {
    }

    $warning = collect($logged)->firstWhere('level', 'warning');

    expect($warning)->not->toBeNull()
        ->and($warning->context['log_id'])->toBe(tiktokFixture('error_invalid_param')['error']['log_id'])
        ->and($warning->context['headers']['Authorization'])->toBe('***')
        ->and(json_encode($logged))->not->toContain('act.valid-token');
});
