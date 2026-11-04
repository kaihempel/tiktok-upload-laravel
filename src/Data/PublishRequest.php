<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Data;

use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\VideoFile;

final readonly class PublishRequest
{
    /**
     * @param  array<string, mixed>  $options  driver specific options (e.g. ['source' => 'url'])
     */
    public function __construct(
        public string $accountId,
        public VideoFile|PhotoSet $media,
        public ?string $caption = null,
        public ?string $reference = null,
        public array $options = [],
    ) {}
}
