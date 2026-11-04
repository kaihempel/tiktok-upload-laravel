<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media\UrlProviders;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use KaiHempel\TikTokUpload\Contracts\RevocableMediaUrlProvider;

/**
 * Serves files through a temporarily signed route of this application
 * (GET /tiktok/media/{token}.{ext}). The route's URL prefix has to be verified
 * in the TikTok developer portal.
 */
class SignedRouteUrlProvider implements RevocableMediaUrlProvider
{
    public const string ROUTE = 'tiktok-upload.media';

    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly StringEncrypter $encrypter,
        private readonly Cache $cache,
        private readonly int $ttlMinutes = 60,
        private readonly ?string $baseUrl = null,
    ) {}

    public function urlFor(string $disk, string $path): string
    {
        $token = $this->encode([
            'd' => $disk,
            'p' => $path,
            'n' => Str::random(16),
        ]);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin';

        $relative = $this->urls->temporarySignedRoute(
            self::ROUTE,
            Carbon::now()->addMinutes($this->ttlMinutes),
            ['token' => $token, 'extension' => $extension],
            false,
        );

        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            return rtrim($this->baseUrl, '/').'/'.ltrim($relative, '/');
        }

        return $this->urls->to($relative);
    }

    public function revoke(string $url): void
    {
        $payload = $this->decodeUrl($url);

        if ($payload !== null) {
            $this->cache->put($this->revocationKey($payload['nonce']), true, Carbon::now()->addMinutes($this->ttlMinutes + 5));
        }
    }

    public function isRevoked(string $nonce): bool
    {
        return (bool) $this->cache->get($this->revocationKey($nonce), false);
    }

    /**
     * @return array{disk: string, path: string, nonce: string}|null
     */
    public function decode(string $token): ?array
    {
        // Laravel's encrypted payload is base64; the token is its URL-safe variant.
        $raw = strtr($token, '-_', '+/');
        $raw .= str_repeat('=', (4 - strlen($raw) % 4) % 4);

        try {
            $payload = json_decode($this->encrypter->decryptString($raw), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ! is_string($payload['d'] ?? null) || ! is_string($payload['p'] ?? null) || ! is_string($payload['n'] ?? null)) {
            return null;
        }

        return ['disk' => $payload['d'], 'path' => $payload['p'], 'nonce' => $payload['n']];
    }

    /**
     * @return array{disk: string, path: string, nonce: string}|null
     */
    public function decodeUrl(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/media/([A-Za-z0-9_\-]+)\.[A-Za-z0-9]+$~', $path, $matches) !== 1) {
            return null;
        }

        return $this->decode($matches[1]);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function encode(array $payload): string
    {
        $encrypted = $this->encrypter->encryptString((string) json_encode($payload));

        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    private function revocationKey(string $nonce): string
    {
        return 'tiktok-upload:media-revoked:'.$nonce;
    }
}
