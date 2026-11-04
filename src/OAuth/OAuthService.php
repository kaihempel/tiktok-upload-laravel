<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\OAuth;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;
use KaiHempel\TikTokUpload\Data\UserProfile;
use KaiHempel\TikTokUpload\Events\TikTokAccountConnected;
use KaiHempel\TikTokUpload\Events\TikTokAccountDisconnected;
use KaiHempel\TikTokUpload\Events\TikTokAccountReauthorizationRequired;
use KaiHempel\TikTokUpload\Events\TikTokTokenRefreshed;
use KaiHempel\TikTokUpload\Exceptions\AuthenticationException;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use KaiHempel\TikTokUpload\Http\ApiTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TikTok Login Kit v2: authorize URL, code exchange, refresh and revoke.
 */
class OAuthService
{
    public const string TOKEN_PATH = '/v2/oauth/token/';

    public const string REVOKE_PATH = '/v2/oauth/revoke/';

    public const string USER_INFO_PATH = '/v2/user/info/';

    /**
     * @param  array{client_key?: string|null, client_secret?: string|null, redirect_uri?: string|null, scopes?: list<string>, authorize_url?: string, pkce?: bool, pkce_encoding?: string}  $config
     */
    public function __construct(
        private readonly ApiTransport $transport,
        private readonly TokenRepository $tokens,
        private readonly Dispatcher $events,
        private readonly array $config,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function usesPkce(): bool
    {
        return (bool) ($this->config['pkce'] ?? false);
    }

    /**
     * Creates the redirect URL including a random state and (optionally) PKCE.
     */
    public function createAuthorizationRequest(): AuthorizationRequest
    {
        $state = Str::random(40);
        $pkce = $this->usesPkce() ? Pkce::generate($this->config['pkce_encoding'] ?? 'hex') : null;

        return new AuthorizationRequest($this->authorizationUrl($state, $pkce), $state, $pkce?->verifier);
    }

    public function authorizationUrl(string $state, ?Pkce $pkce = null): string
    {
        $query = [
            'client_key' => $this->clientKey(),
            'scope' => implode(',', $this->config['scopes'] ?? ['user.info.basic', 'video.upload']),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ];

        if ($pkce !== null) {
            $query['code_challenge'] = $pkce->challenge;
            $query['code_challenge_method'] = $pkce->method;
        }

        $base = $this->config['authorize_url'] ?? 'https://www.tiktok.com/v2/auth/authorize/';

        return $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, ?string $codeVerifier = null): AccessToken
    {
        $form = [
            'client_key' => $this->clientKey(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ];

        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        return AccessToken::fromResponse($this->transport->form(self::TOKEN_PATH, $form));
    }

    /**
     * Exchanges the code, loads the profile, stores the account and fires TikTokAccountConnected.
     */
    public function connect(string $code, Model $owner, ?string $codeVerifier = null): TikTokAccountData
    {
        $token = $this->exchangeCode($code, $codeVerifier);
        $profile = null;

        try {
            $profile = $this->fetchUserProfile($token);
        } catch (TikTokException $e) {
            // The profile is optional: a missing user.info.basic scope must not block the connection.
            $this->logger->warning('Could not load TikTok user profile.', ['code' => $e->errorCode, 'log_id' => $e->logId]);
        }

        $account = $this->tokens->store($token, $owner, $profile);

        $this->events->dispatch(new TikTokAccountConnected($account->id, $account->openId));

        return $account;
    }

    public function fetchUserProfile(AccessToken $token): UserProfile
    {
        $data = $this->transport->json('GET', self::USER_INFO_PATH, $token->accessToken, [], [
            'fields' => 'open_id,avatar_url,display_name',
        ]);

        $user = is_array($data['user'] ?? null) ? $data['user'] : [];

        return new UserProfile(
            openId: (string) ($user['open_id'] ?? $token->openId),
            displayName: isset($user['display_name']) ? (string) $user['display_name'] : null,
            avatarUrl: isset($user['avatar_url']) ? (string) $user['avatar_url'] : null,
        );
    }

    /**
     * Refreshes the access token. TikTok may rotate the refresh token, the new
     * one is always stored.
     */
    public function refresh(TikTokAccountData|string $account): TikTokAccountData
    {
        $account = $this->resolve($account);

        if ($account->isRevoked()) {
            throw new AuthenticationException("TikTok account [{$account->id}] has been revoked.", 'account_revoked');
        }

        if ($account->refreshTokenExpired()) {
            $this->events->dispatch(new TikTokAccountReauthorizationRequired($account->id, 'refresh_token_expired'));

            throw new AuthenticationException('The TikTok refresh token has expired. The account has to be re-authorized.', 'refresh_token_expired');
        }

        try {
            $response = $this->transport->form(self::TOKEN_PATH, [
                'client_key' => $this->clientKey(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refreshToken,
            ]);
        } catch (AuthenticationException $e) {
            $this->events->dispatch(new TikTokAccountReauthorizationRequired($account->id, $e->errorCode ?? 'refresh_failed'));

            throw $e;
        }

        $token = AccessToken::fromResponse($response + ['open_id' => $account->openId]);

        $this->tokens->update($account->id, $token);
        $this->events->dispatch(new TikTokTokenRefreshed($account->id));

        return $this->resolve($account->id);
    }

    /**
     * Revokes the token at TikTok, marks the account as revoked and fires
     * TikTokAccountDisconnected. With $deleteData all stored tokens and profile
     * data are removed (GDPR).
     */
    public function revoke(TikTokAccountData|string $account, bool $deleteData = false): void
    {
        $account = $this->resolve($account);

        if (! $account->isRevoked()) {
            try {
                $this->transport->form(self::REVOKE_PATH, [
                    'client_key' => $this->clientKey(),
                    'client_secret' => $this->clientSecret(),
                    'token' => $account->accessToken,
                ]);
            } catch (AuthenticationException $e) {
                // Token is already invalid at TikTok: nothing left to revoke remotely.
                $this->logger->info('TikTok token was already invalid while revoking.', ['account_id' => $account->id, 'log_id' => $e->logId]);
            }

            $this->tokens->markRevoked($account->id);
        }

        if ($deleteData) {
            $this->tokens->delete($account->id);
        }

        $this->events->dispatch(new TikTokAccountDisconnected($account->id));
    }

    private function resolve(TikTokAccountData|string $account): TikTokAccountData
    {
        if ($account instanceof TikTokAccountData) {
            return $account;
        }

        return $this->tokens->find($account)
            ?? throw new AuthenticationException("TikTok account [{$account}] does not exist.", 'account_not_found');
    }

    private function clientKey(): string
    {
        return $this->required('client_key');
    }

    private function clientSecret(): string
    {
        return $this->required('client_secret');
    }

    private function redirectUri(): string
    {
        return $this->required('redirect_uri');
    }

    private function required(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new TikTokException("The TikTok configuration value [tiktok-upload.{$key}] is missing.", 'configuration_missing');
        }

        return $value;
    }
}
