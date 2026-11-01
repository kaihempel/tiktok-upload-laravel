<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

final readonly class ProbeResult
{
    public function __construct(
        public ?string $codec = null,
        public ?float $fps = null,
        public ?float $durationSeconds = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
