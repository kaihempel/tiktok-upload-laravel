<?php

declare(strict_types=1);

use Kuweh\TikTokUpload\OAuth\Pkce;

it('generates a valid verifier', function (): void {
    $pkce = Pkce::generate();

    expect($pkce->verifier)->toHaveLength(64)
        ->toMatch('/^[A-Za-z0-9\-._~]+$/')
        ->and($pkce->method)->toBe('S256');
});

it('creates the challenge from the RFC 7636 test vector', function (): void {
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    expect(Pkce::challengeFor($verifier, 'base64url'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and(Pkce::challengeFor($verifier, 'hex'))->toBe(hash('sha256', $verifier));
});

it('rejects invalid lengths and encodings', function (): void {
    expect(fn () => Pkce::randomVerifier(10))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pkce::challengeFor('x', 'md5'))->toThrow(InvalidArgumentException::class);
});
