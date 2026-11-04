<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\OAuth;

use Carbon\CarbonImmutable;
use KaiHempel\TikTokUpload\Exceptions\UnexpectedResponseException;

/**
 * Value object for a TikTok access/refresh token pair.
 */
final readonly class AccessToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $openId,
        public string $accessToken,
        public CarbonImmutable $expiresAt,
        public string $refreshToken,
        public CarbonImmutable $refreshExpiresAt,
        public array $scopes = [],
        public string $tokenType = 'Bearer',
    ) {}

    /**
     * @param  array<string, mixed>  $response  body of POST /v2/oauth/token/
     */
    public static function fromResponse(array $response, ?CarbonImmutable $now = null): self
    {
        foreach (['open_id', 'access_token', 'expires_in', 'refresh_token', 'refresh_expires_in'] as $key) {
            if (! isset($response[$key]) || $response[$key] === '') {
                throw new UnexpectedResponseException("TikTok token response is missing [{$key}].", 'invalid_token_response');
            }
        }

        $now ??= CarbonImmutable::now();
        $scope = (string) ($response['scope'] ?? '');

        return new self(
            openId: (string) $response['open_id'],
            accessToken: (string) $response['access_token'],
            expiresAt: $now->addSeconds((int) $response['expires_in']),
            refreshToken: (string) $response['refresh_token'],
            refreshExpiresAt: $now->addSeconds((int) $response['refresh_expires_in']),
            scopes: self::parseScopes($scope),
            tokenType: (string) ($response['token_type'] ?? 'Bearer'),
        );
    }

    /**
     * @return list<string>
     */
    public static function parseScopes(string $scope): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[,\s]+/', $scope) ?: [])));
    }

    public function expiresWithin(int $minutes): bool
    {
        return $this->expiresAt->lessThanOrEqualTo(CarbonImmutable::now()->addMinutes($minutes));
    }

    /**
     * Never expose token values when dumping the object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'openId' => $this->openId,
            'accessToken' => '***',
            'expiresAt' => $this->expiresAt->toIso8601String(),
            'refreshToken' => '***',
            'refreshExpiresAt' => $this->refreshExpiresAt->toIso8601String(),
            'scopes' => $this->scopes,
        ];
    }
}
