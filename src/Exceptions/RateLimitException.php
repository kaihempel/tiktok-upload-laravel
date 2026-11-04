<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Exceptions;

use Throwable;

class RateLimitException extends TikTokException
{
    public function __construct(
        string $message = '',
        ?string $errorCode = 'rate_limit_exceeded',
        ?string $logId = null,
        ?int $httpStatus = 429,
        public readonly int $retryAfter = 60,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $logId, $httpStatus, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
