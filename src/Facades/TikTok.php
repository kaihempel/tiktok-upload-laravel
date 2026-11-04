<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Facades;

use Illuminate\Support\Facades\Facade;
use KaiHempel\TikTokUpload\TikTokManager;

/**
 * @method static \KaiHempel\TikTokUpload\TikTokAccountClient forAccount(string $accountId)
 * @method static \KaiHempel\TikTokUpload\OAuth\OAuthService oauth()
 * @method static \KaiHempel\TikTokUpload\Drivers\TikTokDriver driver()
 * @method static \KaiHempel\TikTokUpload\Media\MediaValidator validator()
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
