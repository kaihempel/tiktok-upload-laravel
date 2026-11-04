<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class TikTokUploadTimedOut
{
    use Dispatchable;

    public function __construct(
        public readonly string $accountId,
        public readonly string $publishId,
        public readonly ?string $reference,
    ) {}
}
