<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

final readonly class UploadResult
{
    /**
     * @param  list<string>  $mediaUrls  URLs handed to TikTok for PULL_FROM_URL uploads
     */
    public function __construct(
        public string $publishId,
        public PublishStatus $status,
        public string $accountId,
        public ?string $reference,
        public ?string $captionForUser,  // videos: text the user has to add manually in the app
        public MediaType $mediaType = MediaType::Video,
        public array $mediaUrls = [],
    ) {}
}
