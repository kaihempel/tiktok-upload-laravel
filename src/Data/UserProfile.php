<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

final readonly class UserProfile
{
    public function __construct(
        public string $openId,
        public ?string $displayName = null,
        public ?string $avatarUrl = null,
    ) {}
}
