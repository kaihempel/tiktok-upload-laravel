<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Contracts;

use Kuweh\TikTokUpload\Data\ProbeResult;

interface MediaProbe
{
    public function probe(string $disk, string $path): ProbeResult;
}
