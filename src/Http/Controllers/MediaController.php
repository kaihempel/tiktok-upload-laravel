<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Http\Controllers;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kuweh\TikTokUpload\Media\UrlProviders\SignedRouteUrlProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers media files to TikTok for PULL_FROM_URL uploads via signed URLs.
 */
class MediaController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        string $extension,
        SignedRouteUrlProvider $provider,
        FilesystemFactory $filesystems,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(false), 403, 'Invalid or expired signature.');

        $payload = $provider->decode($token);

        abort_if($payload === null, 404);
        abort_if($provider->isRevoked($payload['nonce']), 410, 'This media URL has been revoked.');

        $disk = $filesystems->disk($payload['disk']);

        abort_unless($disk instanceof FilesystemAdapter && $disk->exists($payload['path']), 404);

        return $disk->response($payload['path'], basename($payload['path']), [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
