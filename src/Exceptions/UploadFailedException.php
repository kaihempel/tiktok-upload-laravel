<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Exceptions;

use Throwable;

/**
 * The upload failed, e.g. TikTok reported FAILED with a fail_reason or a
 * chunk could not be transferred.
 */
class UploadFailedException extends TikTokException
{
    public function __construct(
        string $message = '',
        ?string $errorCode = 'upload_failed',
        ?string $logId = null,
        ?int $httpStatus = null,
        public readonly ?string $publishId = null,
        ?Throwable $previous = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message, $errorCode, $logId, $httpStatus, $previous);
    }

    /**
     * Transport problems (network errors, 5xx while uploading chunks) may be
     * retried, a FAILED status reported by TikTok may not.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
