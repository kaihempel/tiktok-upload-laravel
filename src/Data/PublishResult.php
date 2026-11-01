<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

final readonly class PublishResult
{
    public function __construct(
        public string $remoteId,
        public PublishState $state,
        public string $platformStatus,
        public ?string $reference = null,
        public ?string $captionForUser = null,
        public bool $requiresUserAction = false,
        public ?string $error = null,
    ) {}
}
