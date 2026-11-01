<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class TikTokTokenRefreshed
{
    use Dispatchable;

    public function __construct(
        public readonly string $accountId,
    ) {}
}
