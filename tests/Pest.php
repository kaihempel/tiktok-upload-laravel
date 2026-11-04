<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;
use KaiHempel\TikTokUpload\OAuth\AccessToken;
use KaiHempel\TikTokUpload\Tests\Fixtures\User;
use KaiHempel\TikTokUpload\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->use(RefreshDatabase::class)->in('Feature');

/**
 * Loads a JSON fixture from tests/Fixtures/tiktok.
 *
 * @return array<string, mixed>
 */
function tiktokFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/Fixtures/tiktok/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

function createUser(string $email = 'kai@example.de'): User
{
    return User::query()->create(['name' => 'Kai', 'email' => $email, 'password' => 'secret']);
}

/**
 * Stores a connected account with a token valid for $expiresInMinutes.
 */
function createAccount(?User $owner = null, int $expiresInMinutes = 600, string $openId = 'open-id-123', int $refreshExpiresInMinutes = 60 * 24 * 300): TikTokAccountData
{
    $now = CarbonImmutable::now();

    return app(TokenRepository::class)->store(new AccessToken(
        openId: $openId,
        accessToken: 'act.valid-token',
        expiresAt: $now->addMinutes($expiresInMinutes),
        refreshToken: 'rft.refresh-token',
        refreshExpiresAt: $now->addMinutes($refreshExpiresInMinutes),
        scopes: ['user.info.basic', 'video.upload'],
    ), $owner ?? createUser());
}

/**
 * Builds a TikTok API envelope.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function tiktokResponse(array $data = [], string $code = 'ok', string $message = '', string $logId = '202611011200000000TEST'): array
{
    return ['data' => $data, 'error' => ['code' => $code, 'message' => $message, 'log_id' => $logId]];
}
