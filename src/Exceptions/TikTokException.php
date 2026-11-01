<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for every error returned by TikTok or raised by this package.
 */
class TikTokException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?string $errorCode = null,
        public readonly ?string $logId = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * Whether a queued job may retry the operation later.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
