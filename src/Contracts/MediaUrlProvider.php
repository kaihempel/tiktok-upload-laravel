<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Contracts;

interface MediaUrlProvider
{
    /**
     * Returns a publicly reachable HTTPS URL on a domain verified in TikTok.
     */
    public function urlFor(string $disk, string $path): string;
}
