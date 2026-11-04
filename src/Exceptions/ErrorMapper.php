<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Exceptions;

/**
 * Maps TikTok error codes onto the package's exception hierarchy.
 */
final class ErrorMapper
{
    public static function map(
        string $code,
        string $message = '',
        ?string $logId = null,
        ?int $httpStatus = null,
        ?int $retryAfter = null,
    ): TikTokException {
        $message = $message !== '' ? $message : "TikTok API error [{$code}].";

        return match (true) {
            $code === 'access_token_invalid',
            $code === 'invalid_grant',
            $code === 'invalid_client' => new AuthenticationException($message, $code, $logId, $httpStatus),
            $code === 'scope_not_authorized',
            $code === 'scope_permission_missed' => new ScopeNotAuthorizedException($message, $code, $logId, $httpStatus),
            $code === 'rate_limit_exceeded' || $httpStatus === 429 => new RateLimitException($message, $code, $logId, $httpStatus, $retryAfter ?? 60),
            $code === 'spam_risk_too_many_pending_share' => new PendingShareLimitReachedException($message, $code, $logId, $httpStatus),
            $code === 'spam_risk_too_many_posts' => new DailyPostLimitReachedException($message, $code, $logId, $httpStatus),
            $code === 'url_ownership_unverified' => new UrlOwnershipUnverifiedException($message, $code, $logId, $httpStatus),
            $code === 'internal_error',
            $httpStatus !== null && $httpStatus >= 500 => new ServerException($message, $code, $logId, $httpStatus),
            default => new TikTokException($message, $code, $logId, $httpStatus),
        };
    }
}
