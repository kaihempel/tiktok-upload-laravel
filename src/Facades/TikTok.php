<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Facades;

use Illuminate\Support\Facades\Facade;
use Kuweh\TikTokUpload\TikTokManager;

/**
 * @method static \Kuweh\TikTokUpload\TikTokAccountClient forAccount(string $accountId)
 * @method static \Kuweh\TikTokUpload\OAuth\OAuthService oauth()
 * @method static \Kuweh\TikTokUpload\Drivers\TikTokDriver driver()
 * @method static \Kuweh\TikTokUpload\Media\MediaValidator validator()
 * @method static void resolveOwnerUsing(\Closure|null $resolver)
 *
 * @see TikTokManager
 */
class TikTok extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TikTokManager::class;
    }
}
