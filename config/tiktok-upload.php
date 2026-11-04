<?php

use KaiHempel\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use KaiHempel\TikTokUpload\Models\TikTokAccount;
use KaiHempel\TikTokUpload\Repositories\EloquentTokenRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | TikTok app credentials
    |--------------------------------------------------------------------------
    |
    | Credentials of the app registered at developers.tiktok.com. The client
    | secret must only ever come from the environment (.env).
    |
    */

    'client_key' => env('TIKTOK_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
    'scopes' => ['user.info.basic', 'video.upload'],

    /*
    |--------------------------------------------------------------------------
    | API endpoints
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'authorize' => 'https://www.tiktok.com/v2/auth/authorize/',
        'api' => 'https://open.tiktokapis.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    |
    | PKCE is officially documented by TikTok for desktop/mobile clients (with a
    | hex encoded SHA-256 challenge). Whether the web flow honours it has to be
    | verified against the current documentation, therefore it is opt-in.
    |
    */

    'pkce' => [
        'enabled' => env('TIKTOK_PKCE', false),
        'challenge_encoding' => 'hex', // 'hex' (TikTok docs) | 'base64url' (RFC 7636)
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,              // OAuth routes (connect, callback, disconnect)
        'prefix' => 'tiktok',
        'middleware' => ['web', 'auth'],
        'success_redirect' => '/settings/accounts',
        'failure_redirect' => '/settings/accounts',

        // The media route is called by TikTok's servers and must therefore not
        // be protected by authentication. It is secured by a signed URL.
        'media_enabled' => true,
        'media_middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token storage
    |--------------------------------------------------------------------------
    */

    'token_repository' => EloquentTokenRepository::class,
    'account_model' => TikTokAccount::class,
    'refresh_before_expiry_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    'upload' => [
        'default_source' => 'file',          // 'file' | 'url'
        'chunk_size' => 10 * 1024 * 1024,    // 10 MB (allowed: 5 MB – 64 MB)
        'timeout' => 300,
        'chunk_retries' => 3,
        'chunk_retry_backoff_ms' => 1000,    // doubled on every retry
        'upload_url_ttl_seconds' => 3300,    // TikTok: ~1 h, re-init a bit earlier
    ],

    /*
    |--------------------------------------------------------------------------
    | Media validation (see TikTok media requirements)
    |--------------------------------------------------------------------------
    */

    'video' => [
        'extensions' => ['mp4', 'webm', 'mov'],
        'mime_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'codecs' => ['h264', 'hevc', 'h265', 'vp8', 'vp9'],
        'max_size_bytes' => 4 * 1024 * 1024 * 1024, // 4 GB
        'min_fps' => 23,
        'max_fps' => 60,
        'min_duration_seconds' => 3,
        'max_duration_seconds' => 600,
        'min_dimension' => 360,
        'max_dimension' => 4096,
    ],

    'photo' => [
        'extensions' => ['jpg', 'jpeg', 'webp'],
        'mime_types' => ['image/jpeg', 'image/webp'],
        'max_size_bytes' => 20 * 1024 * 1024, // 20 MB
        'max_long_edge' => 1920,
        'max_short_edge' => 1080,
        'max_photos' => 35,
        'max_title_length' => 90,           // UTF-16 code units
        'max_description_length' => 4000,   // UTF-16 code units
    ],

    /*
    |--------------------------------------------------------------------------
    | Media URLs (PULL_FROM_URL)
    |--------------------------------------------------------------------------
    */

    'media_url' => [
        'provider' => SignedRouteUrlProvider::class,
        'disk' => 'public',
        'ttl_minutes' => 60,
        'base_url' => env('TIKTOK_MEDIA_BASE_URL'), // must be verified in TikTok
        'invalidate_after_inbox' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Status polling
    |--------------------------------------------------------------------------
    */

    'polling' => [
        'interval_seconds' => 15,
        'max_attempts' => 80,   // ~20 minutes

        // Optional low frequency polling after SEND_TO_USER_INBOX to detect
        // PUBLISH_COMPLETE once the user posted the draft manually.
        'long_term' => [
            'enabled' => false,
            'interval_seconds' => 3600,
            'max_attempts' => 48,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits and quotas
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'requests_per_minute' => 6,
    ],

    'pending_shares' => [
        'track' => true,
        'limit' => 5,          // open inbox drafts per 24 h
        'block' => false,      // true = throw locally before calling TikTok
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => null,
        'queue' => 'tiktok',
        'tries' => 5,
        'backoff' => [10, 30, 90, 270],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media probing (ffprobe) and transformation hook
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'enabled' => false,
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout' => 60,
    ],

    // Class implementing KaiHempel\TikTokUpload\Contracts\MediaTransformer, e.g. to
    // transcode videos with ffmpeg or to convert PNG images to JPEG.
    'transformer' => null,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('TIKTOK_LOG_CHANNEL'),
    ],
];
