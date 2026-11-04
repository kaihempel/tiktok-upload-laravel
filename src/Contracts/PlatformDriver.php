<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Contracts;

use KaiHempel\TikTokUpload\Data\Capabilities;
use KaiHempel\TikTokUpload\Data\PublishRequest;
use KaiHempel\TikTokUpload\Data\PublishResult;

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
