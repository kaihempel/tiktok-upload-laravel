<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Data;

use Carbon\CarbonImmutable;
use KaiHempel\TikTokUpload\OAuth\AccessToken;

final readonly class TikTokAccountData
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $openId,
        public ?string $displayName,
        public ?string $avatarUrl,
        public array $scopes,
        public string $accessToken,
        public CarbonImmutable $accessTokenExpiresAt,
        public string $refreshToken,
        public CarbonImmutable $refreshTokenExpiresAt,
        public ?CarbonImmutable $revokedAt = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function accessTokenExpiresWithin(int $minutes): bool
    {
        return $this->accessTokenExpiresAt->lessThanOrEqualTo(CarbonImmutable::now()->addMinutes($minutes));
    }

    public function refreshTokenExpired(): bool
    {
        return $this->refreshTokenExpiresAt->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function token(): AccessToken
    {
        return new AccessToken(
            openId: $this->openId,
            accessToken: $this->accessToken,
            expiresAt: $this->accessTokenExpiresAt,
            refreshToken: $this->refreshToken,
            refreshExpiresAt: $this->refreshTokenExpiresAt,
            scopes: $this->scopes,
        );
    }
}
