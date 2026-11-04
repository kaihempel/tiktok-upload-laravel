<?php

use Illuminate\Support\Facades\Route;
use KaiHempel\TikTokUpload\Http\Controllers\MediaController;
use KaiHempel\TikTokUpload\Http\Controllers\OAuthController;

$prefix = (string) config('tiktok-upload.routes.prefix', 'tiktok');

if (config('tiktok-upload.routes.enabled', true)) {
    Route::prefix($prefix)
        ->middleware((array) config('tiktok-upload.routes.middleware', ['web', 'auth']))
        ->name('tiktok-upload.')
        ->group(function (): void {
            Route::get('connect', [OAuthController::class, 'connect'])->name('connect');
            Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
            Route::post('accounts/{id}/disconnect', [OAuthController::class, 'disconnect'])->name('disconnect');
        });
}

if (config('tiktok-upload.routes.media_enabled', true)) {
    Route::prefix($prefix)
        ->middleware((array) config('tiktok-upload.routes.media_middleware', []))
        ->name('tiktok-upload.')
        ->group(function (): void {
            Route::get('media/{token}.{extension}', MediaController::class)
                ->where(['token' => '[A-Za-z0-9_\-]+', 'extension' => '[A-Za-z0-9]+'])
                ->name('media');
        });
}
