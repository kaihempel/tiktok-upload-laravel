<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use KaiHempel\TikTokUpload\Exceptions\UnexpectedResponseException;
use KaiHempel\TikTokUpload\OAuth\AccessToken;

it('builds a token from the token response', function (): void {
    $now = CarbonImmutable::parse('2026-11-01 12:00:00');
    $token = AccessToken::fromResponse(tiktokFixture('token'), $now);

    expect($token->openId)->toBe('-000OqnTqvyAs6PXm0P1Kpv_mZr0FbM4oQJT')
        ->and($token->expiresAt->toDateTimeString())->toBe('2026-11-02 12:00:00')
        ->and($token->refreshExpiresAt->toDateTimeString())->toBe('2027-11-01 12:00:00')
        ->and($token->scopes)->toBe(['user.info.basic', 'video.upload'])
        ->and($token->tokenType)->toBe('Bearer');
});

it('detects tokens that expire soon', function (): void {
    $token = AccessToken::fromResponse(['expires_in' => 600] + tiktokFixture('token'));

    expect($token->expiresWithin(30))->toBeTrue()
        ->and($token->expiresWithin(5))->toBeFalse();
});

it('fails on incomplete responses', function (): void {
    AccessToken::fromResponse(['access_token' => 'x']);
})->throws(UnexpectedResponseException::class);

it('never exposes token values in debug output', function (): void {
    $dump = print_r(AccessToken::fromResponse(tiktokFixture('token')), true);

    expect($dump)->not->toContain('act.')->not->toContain('rft.');
});
