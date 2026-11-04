<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Contracts;

use KaiHempel\TikTokUpload\Data\ProbeResult;

interface MediaProbe
{
    public function probe(string $disk, string $path): ProbeResult;
}
