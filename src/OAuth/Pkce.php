<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\OAuth;

use InvalidArgumentException;

/**
 * PKCE (RFC 7636) helper. TikTok documents a hex encoded SHA-256 challenge for
 * its desktop flow; RFC 7636 uses base64url. Both are supported.
 */
final readonly class Pkce
{
    private const string CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    public function __construct(
        public string $verifier,
        public string $challenge,
        public string $method = 'S256',
    ) {}

    public static function generate(string $encoding = 'hex', int $length = 64): self
    {
        $verifier = self::randomVerifier($length);

        return new self($verifier, self::challengeFor($verifier, $encoding));
    }

    public static function randomVerifier(int $length = 64): string
    {
        if ($length < 43 || $length > 128) {
            throw new InvalidArgumentException('The PKCE code verifier must be between 43 and 128 characters long.');
        }

        $max = strlen(self::CHARSET) - 1;
        $verifier = '';

        for ($i = 0; $i < $length; $i++) {
            $verifier .= self::CHARSET[random_int(0, $max)];
        }

        return $verifier;
    }

    public static function challengeFor(string $verifier, string $encoding = 'hex'): string
    {
        return match ($encoding) {
            'hex' => hash('sha256', $verifier),
            'base64url' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            default => throw new InvalidArgumentException("Unsupported PKCE challenge encoding [{$encoding}]."),
        };
    }
}
