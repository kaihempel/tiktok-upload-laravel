<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Contracts;

use Kuweh\TikTokUpload\Data\Capabilities;
use Kuweh\TikTokUpload\Data\PublishRequest;
use Kuweh\TikTokUpload\Data\PublishResult;

/**
 * Common contract used by the social media manager for every platform.
 */
interface PlatformDriver
{
    public function key(): string;

    public function capabilities(): Capabilities;

    public function publish(PublishRequest $request): PublishResult;

    public function status(string $remoteId): PublishResult;
}
