<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Contracts;

/**
 * Implemented by jobs that act on behalf of a TikTok account (used by the
 * per-account rate limiter middleware).
 */
interface HasTikTokAccount
{
    public function tikTokAccountId(): string;
}
