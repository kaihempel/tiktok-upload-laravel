<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kuweh\TikTokUpload\Facades\TikTok;
use Kuweh\TikTokUpload\TikTokManager;
use Kuweh\TikTokUpload\TikTokUploadServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        TikTokManager::resolveOwnerUsing(null);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [TikTokUploadServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['TikTok' => TikTok::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('tiktok-upload.client_key', 'test-client-key');
        $app['config']->set('tiktok-upload.client_secret', 'test-client-secret');
        $app['config']->set('tiktok-upload.redirect_uri', 'https://app.example.de/tiktok/callback');
        $app['config']->set('tiktok-upload.media_url.base_url', 'https://media.example.de');
        $app['config']->set('tiktok-upload.media_url.disk', 'public');
        $app['config']->set('tiktok-upload.upload.chunk_retry_backoff_ms', 0);
        $app['config']->set('tiktok-upload.queue.queue', 'tiktok');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
}
