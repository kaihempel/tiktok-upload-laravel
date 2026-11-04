<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Data;

final readonly class Capabilities
{
    public function __construct(
        public bool $directPublish,
        public bool $draftUpload,
        public bool $captionInDraft,
        public bool $video,
        public bool $photos,
        public int $maxPhotos,
        public int $maxCaptionLength,
    ) {}
}
