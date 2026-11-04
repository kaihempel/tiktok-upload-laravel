<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\OAuth;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $state,
        public ?string $codeVerifier = null,
    ) {}
}
