<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use KaiHempel\TikTokUpload\Console\RefreshTikTokTokens;
use KaiHempel\TikTokUpload\Contracts\MediaProbe;
use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Drivers\TikTokDriver;
use KaiHempel\TikTokUpload\Http\ApiTransport;
use KaiHempel\TikTokUpload\Http\TikTokHttpClient;
use KaiHempel\TikTokUpload\Media\MediaValidator;
use KaiHempel\TikTokUpload\Media\Probe\FfprobeProbe;
use KaiHempel\TikTokUpload\Media\UrlProviders\DiskUrlProvider;
use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use KaiHempel\TikTokUpload\OAuth\OAuthService;
use KaiHempel\TikTokUpload\Repositories\EloquentTokenRepository;
use KaiHempel\TikTokUpload\Support\PendingShareTracker;
use KaiHempel\TikTokUpload\Upload\ChunkPlanner;
use KaiHempel\TikTokUpload\Upload\PhotoUploader;
use KaiHempel\TikTokUpload\Upload\StatusFetcher;
use KaiHempel\TikTokUpload\Upload\VideoUploader;
use Psr\Log\LoggerInterface;

class TikTokUploadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tiktok-upload.php', 'tiktok-upload');

        $this->app->bind('tiktok-upload.logger', function (Container $app): LoggerInterface {
            $channel = $this->config($app)->get('tiktok-upload.logging.channel');
            $logs = $app->make(LogManager::class);

            return is_string($channel) && $channel !== '' ? $logs->channel($channel) : $logs->driver();
        });

        $this->app->bind(ApiTransport::class, fn (Container $app): ApiTransport => new ApiTransport(
            $app->make(HttpFactory::class),
            (string) $this->config($app)->get('tiktok-upload.endpoints.api', 'https://open.tiktokapis.com'),
            $app->make('tiktok-upload.logger'),
        ));

        $this->app->bind(TokenRepository::class, function (Container $app): TokenRepository {
            $class = $this->config($app)->get('tiktok-upload.token_repository', EloquentTokenRepository::class);

            if ($class === EloquentTokenRepository::class) {
                return new EloquentTokenRepository(
                    $this->config($app)->get('tiktok-upload.account_model', Models\TikTokAccount::class),
                );
            }

            return $app->make($class);
        });

        $this->app->bind(OAuthService::class, function (Container $app): OAuthService {
            $config = $this->config($app);

            return new OAuthService(
                $app->make(ApiTransport::class),
                $app->make(TokenRepository::class),
                $app->make(Dispatcher::class),
                [
                    'client_key' => $config->get('tiktok-upload.client_key'),
                    'client_secret' => $config->get('tiktok-upload.client_secret'),
                    'redirect_uri' => $config->get('tiktok-upload.redirect_uri'),
                    'scopes' => array_values((array) $config->get('tiktok-upload.scopes', [])),
                    'authorize_url' => (string) $config->get('tiktok-upload.endpoints.authorize', 'https://www.tiktok.com/v2/auth/authorize/'),
                    'pkce' => (bool) $config->get('tiktok-upload.pkce.enabled', false),
                    'pkce_encoding' => (string) $config->get('tiktok-upload.pkce.challenge_encoding', 'hex'),
                ],
                $app->make('tiktok-upload.logger'),
            );
        });

        $this->app->bind(TikTokHttpClient::class, fn (Container $app): TikTokHttpClient => new TikTokHttpClient(
            $app->make(ApiTransport::class),
            $app->make(TokenRepository::class),
            $app->make(OAuthService::class),
            (int) $this->config($app)->get('tiktok-upload.refresh_before_expiry_minutes', 30),
        ));

        $this->app->bind(ChunkPlanner::class, fn (Container $app): ChunkPlanner => new ChunkPlanner(
            (int) $this->config($app)->get('tiktok-upload.upload.chunk_size', 10 * 1024 * 1024),
        ));

        $this->app->bind(MediaProbe::class, fn (Container $app): MediaProbe => new FfprobeProbe(
            $app->make(FilesystemFactory::class),
            (string) $this->config($app)->get('tiktok-upload.probe.ffprobe_binary', 'ffprobe'),
            (int) $this->config($app)->get('tiktok-upload.probe.timeout', 60),
        ));

        $this->app->bind(MediaValidator::class, fn (Container $app): MediaValidator => new MediaValidator(
            (array) $this->config($app)->get('tiktok-upload.video', []),
            (array) $this->config($app)->get('tiktok-upload.photo', []),
            $this->config($app)->get('tiktok-upload.probe.enabled', false) ? $app->make(MediaProbe::class) : null,
        ));

        $this->app->bind(SignedRouteUrlProvider::class, fn (Container $app): SignedRouteUrlProvider => new SignedRouteUrlProvider(
            $app->make('url'),
            $app->make('encrypter'),
            $app->make(CacheFactory::class)->store(),
            (int) $this->config($app)->get('tiktok-upload.media_url.ttl_minutes', 60),
            $this->config($app)->get('tiktok-upload.media_url.base_url'),
        ));

        $this->app->bind(DiskUrlProvider::class, fn (Container $app): DiskUrlProvider => new DiskUrlProvider(
            $app->make(FilesystemFactory::class),
            (int) $this->config($app)->get('tiktok-upload.media_url.ttl_minutes', 60),
        ));

        $this->app->bind(MediaUrlProvider::class, fn (Container $app): MediaUrlProvider => $app->make(
            $this->config($app)->get('tiktok-upload.media_url.provider', SignedRouteUrlProvider::class),
        ));

        $this->app->bind(VideoUploader::class, fn (Container $app): VideoUploader => new VideoUploader(
            $app->make(TikTokHttpClient::class),
            $app->make(HttpFactory::class),
            $app->make(ChunkPlanner::class),
            $app->make(MediaUrlProvider::class),
            (array) $this->config($app)->get('tiktok-upload.upload', []),
        ));

        $this->app->bind(PhotoUploader::class, fn (Container $app): PhotoUploader => new PhotoUploader(
            $app->make(TikTokHttpClient::class),
            $app->make(MediaUrlProvider::class),
        ));

        $this->app->bind(StatusFetcher::class, fn (Container $app): StatusFetcher => new StatusFetcher(
            $app->make(TikTokHttpClient::class),
        ));

        $this->app->bind(PendingShareTracker::class, fn (Container $app): PendingShareTracker => new PendingShareTracker(
            $app->make(CacheFactory::class)->store(),
            (int) $this->config($app)->get('tiktok-upload.pending_shares.limit', 5),
        ));

        $this->app->bind(TikTokAccountClient::class, function (Container $app, array $parameters): TikTokAccountClient {
            $config = $this->config($app);
            $transformer = $config->get('tiktok-upload.transformer');

            return new TikTokAccountClient(
                accountId: (string) ($parameters['accountId'] ?? ''),
                client: $app->make(TikTokHttpClient::class),
                oauth: $app->make(OAuthService::class),
                videos: $app->make(VideoUploader::class),
                photos: $app->make(PhotoUploader::class),
                statuses: $app->make(StatusFetcher::class),
                validator: $app->make(MediaValidator::class),
                pendingShares: $app->make(PendingShareTracker::class),
                events: $app->make(Dispatcher::class),
                transformer: is_string($transformer) && $transformer !== '' ? $app->make($transformer) : null,
                options: [
                    'default_source' => (string) $config->get('tiktok-upload.upload.default_source', 'file'),
                    'track_pending_shares' => (bool) $config->get('tiktok-upload.pending_shares.track', true),
                    'block_pending_shares' => (bool) $config->get('tiktok-upload.pending_shares.block', false),
                ],
                logger: $app->make('tiktok-upload.logger'),
            );
        });

        $this->app->bind(TikTokDriver::class, fn (Container $app): TikTokDriver => new TikTokDriver(
            $app->make(TikTokManager::class),
            (int) $this->config($app)->get('tiktok-upload.photo.max_photos', 35),
            (int) $this->config($app)->get('tiktok-upload.photo.max_description_length', 4000),
        ));

        $this->app->singleton(TikTokManager::class, fn (Container $app): TikTokManager => new TikTokManager($app));
        $this->app->alias(TikTokManager::class, 'tiktok-upload');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tiktok-upload.php' => $this->app->configPath('tiktok-upload.php'),
            ], 'tiktok-upload-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'tiktok-upload-migrations');

            $this->commands([RefreshTikTokTokens::class]);
        }

        // OAuth routes (routes.enabled) and the media route (routes.media_enabled)
        // are toggled independently inside the route file.
        $this->loadRoutesFrom(__DIR__.'/../routes/tiktok-upload.php');
    }

    private function config(Container $app): Config
    {
        return $app->make('config');
    }
}
