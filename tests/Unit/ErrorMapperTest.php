<?php

declare(strict_types=1);

use KaiHempel\TikTokUpload\Exceptions\AuthenticationException;
use KaiHempel\TikTokUpload\Exceptions\DailyPostLimitReachedException;
use KaiHempel\TikTokUpload\Exceptions\ErrorMapper;
use KaiHempel\TikTokUpload\Exceptions\PendingShareLimitReachedException;
use KaiHempel\TikTokUpload\Exceptions\RateLimitException;
use KaiHempel\TikTokUpload\Exceptions\ScopeNotAuthorizedException;
use KaiHempel\TikTokUpload\Exceptions\ServerException;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use KaiHempel\TikTokUpload\Exceptions\UrlOwnershipUnverifiedException;

it('maps error codes to exceptions', function (string $code, ?int $status, string $class, bool $retryable): void {
    $exception = ErrorMapper::map($code, 'message', 'log-1', $status);

    expect($exception)->toBeInstanceOf($class)
        ->toBeInstanceOf(TikTokException::class)
        ->and($exception->errorCode)->toBe($code)
        ->and($exception->logId)->toBe('log-1')
        ->and($exception->httpStatus)->toBe($status)
        ->and($exception->isRetryable())->toBe($retryable);
})->with([
    ['access_token_invalid', 401, AuthenticationException::class, false],
    ['scope_not_authorized', 401, ScopeNotAuthorizedException::class, false],
    ['rate_limit_exceeded', 429, RateLimitException::class, true],
    ['spam_risk_too_many_pending_share', 403, PendingShareLimitReachedException::class, false],
    ['spam_risk_too_many_posts', 403, DailyPostLimitReachedException::class, false],
    ['url_ownership_unverified', 403, UrlOwnershipUnverifiedException::class, false],
    ['internal_error', 500, ServerException::class, true],
    ['http_503', 503, ServerException::class, true],
    ['invalid_param', 400, TikTokException::class, false],
]);

it('passes retry-after to rate limit exceptions', function (): void {
    $exception = ErrorMapper::map('rate_limit_exceeded', '', null, 429, 42);

    expect($exception)->toBeInstanceOf(RateLimitException::class)
        ->and($exception->retryAfter)->toBe(42)
        ->and($exception->getMessage())->toContain('rate_limit_exceeded');
});
