<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kuweh\TikTokUpload\Drivers\TikTokDriver;
use Kuweh\TikTokUpload\Media\MediaValidator;
use Kuweh\TikTokUpload\OAuth\OAuthService;

/**
 * Entry point of the package (facade root).
 */
class TikTokManager
{
    /**
     * @var (Closure(Request): (Model|null))|null
     */
    protected static ?Closure $ownerResolver = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function forAccount(string $accountId): TikTokAccountClient
    {
        return $this->container->make(TikTokAccountClient::class, ['accountId' => $accountId]);
    }

    public function oauth(): OAuthService
    {
        return $this->container->make(OAuthService::class);
    }

    public function driver(): TikTokDriver
    {
        return $this->container->make(TikTokDriver::class);
    }

    public function validator(): MediaValidator
    {
        return $this->container->make(MediaValidator::class);
    }

    /**
     * Customise which model owns accounts connected through the bundled routes
     * (default: the authenticated user).
     *
     * @param  (Closure(Request): (Model|null))|null  $resolver
     */
    public static function resolveOwnerUsing(?Closure $resolver): void
    {
        static::$ownerResolver = $resolver;
    }

    public static function resolveOwner(Request $request): ?Model
    {
        if (static::$ownerResolver !== null) {
            return (static::$ownerResolver)($request);
        }

        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }
}
