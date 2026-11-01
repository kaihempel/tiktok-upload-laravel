<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Exceptions;

/**
 * Local validation of media failed. Contains every violation at once.
 */
class MediaValidationException extends TikTokException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            'The media does not meet TikTok\'s requirements: '.implode(' ', $violations),
            'media_validation_failed',
        );
    }
}
