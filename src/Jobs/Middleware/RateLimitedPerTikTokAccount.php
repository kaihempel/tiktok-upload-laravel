<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Jobs\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Config;
use KaiHempel\TikTokUpload\Contracts\HasTikTokAccount;

/**
 * Enforces TikTok's limit of 6 requests per minute and access token on the
 * posting endpoints. Jobs exceeding the limit are released until a slot is free.
 */
class RateLimitedPerTikTokAccount
{
    public function __construct(
        private readonly ?int $perMinute = null,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof HasTikTokAccount) {
            return $next($job);
        }

        $limiter = app(RateLimiter::class);
        $key = self::key($job->tikTokAccountId());
        $max = $this->perMinute ?? (int) Config::get('tiktok-upload.rate_limit.requests_per_minute', 6);

        if ($limiter->tooManyAttempts($key, $max)) {
            if (method_exists($job, 'release')) {
                $job->release(max(1, $limiter->availableIn($key)));
            }

            return null;
        }

        $limiter->hit($key, 60);

        return $next($job);
    }

    public static function key(string $accountId): string
    {
        return 'tiktok-upload:rate-limit:'.$accountId;
    }
}
