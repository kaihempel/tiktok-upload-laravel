<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Contracts;

/**
 * A media URL provider whose URLs can be invalidated before they expire.
 */
interface RevocableMediaUrlProvider extends MediaUrlProvider
{
    public function revoke(string $url): void;
}
