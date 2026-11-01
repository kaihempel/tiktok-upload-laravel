<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Exceptions;

/**
 * TikTok answered with a 5xx status code or an internal error.
 */
class ServerException extends TikTokException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
