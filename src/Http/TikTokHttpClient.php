<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Http;

use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;
use KaiHempel\TikTokUpload\Exceptions\AuthenticationException;
use KaiHempel\TikTokUpload\OAuth\OAuthService;

/**
 * Account aware API client. Before every request it makes sure the access
 * token is valid for at least `refresh_before_expiry_minutes` and refreshes it
 * otherwise. An access_token_invalid answer triggers one refresh + retry.
 */
class TikTokHttpClient
{
    public function __construct(
        private readonly ApiTransport $transport,
        private readonly TokenRepository $tokens,
        private readonly OAuthService $oauth,
        private readonly int $refreshBeforeExpiryMinutes = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $accountId, string $path, array $payload = []): array
    {
        return $this->send($accountId, 'POST', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $accountId, string $path, array $query = []): array
    {
        return $this->send($accountId, 'GET', $path, [], $query);
    }

    public function account(string $accountId): TikTokAccountData
    {
        $account = $this->tokens->find($accountId);

        if ($account === null) {
            throw new AuthenticationException("TikTok account [{$accountId}] does not exist.", 'account_not_found');
        }

        if ($account->isRevoked()) {
            throw new AuthenticationException("TikTok account [{$accountId}] has been revoked.", 'account_revoked');
        }

        return $account;
    }

    /**
     * Returns the account with a token that is valid for the configured window.
     */
    public function freshAccount(string $accountId): TikTokAccountData
    {
        $account = $this->account($accountId);

        if ($account->accessTokenExpiresWithin($this->refreshBeforeExpiryMinutes)) {
            $account = $this->oauth->refresh($account);
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $accountId, string $method, string $path, array $payload, array $query = []): array
    {
        $account = $this->freshAccount($accountId);

        try {
            return $this->transport->json($method, $path, $account->accessToken, $payload, $query);
        } catch (AuthenticationException $e) {
            if ($e->errorCode !== 'access_token_invalid') {
                throw $e;
            }

            $account = $this->oauth->refresh($account);

            return $this->transport->json($method, $path, $account->accessToken, $payload, $query);
        }
    }
}
