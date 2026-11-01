<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\OAuth;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $state,
        public ?string $codeVerifier = null,
    ) {}
}
