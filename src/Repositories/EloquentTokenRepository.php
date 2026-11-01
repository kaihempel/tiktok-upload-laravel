<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Repositories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kuweh\TikTokUpload\Contracts\TokenRepository;
use Kuweh\TikTokUpload\Data\TikTokAccountData;
use Kuweh\TikTokUpload\Data\UserProfile;
use Kuweh\TikTokUpload\Models\TikTokAccount;
use Kuweh\TikTokUpload\OAuth\AccessToken;

class EloquentTokenRepository implements TokenRepository
{
    /**
     * @param  class-string<TikTokAccount>  $model
     */
    public function __construct(
        private readonly string $model = TikTokAccount::class,
    ) {}

    public function find(string $accountId): ?TikTokAccountData
    {
        return $this->query()->find($accountId)?->toData();
    }

    public function store(AccessToken $token, Model $owner, ?UserProfile $profile = null): TikTokAccountData
    {
        /** @var TikTokAccount $account */
        $account = $this->query()->firstOrNew(['open_id' => $token->openId]);

        $account->owner()->associate($owner);
        $account->fill($this->tokenAttributes($token) + ['revoked_at' => null]);

        if ($profile !== null) {
            $account->display_name = $profile->displayName;
            $account->avatar_url = $profile->avatarUrl;
        }

        $account->save();

        return $account->toData();
    }

    public function update(string $accountId, AccessToken $token): void
    {
        $this->query()->whereKey($accountId)->firstOrFail()->update($this->tokenAttributes($token));
    }

    public function markRevoked(string $accountId): void
    {
        $this->query()->whereKey($accountId)->update(['revoked_at' => now()]);
    }

    public function delete(string $accountId): void
    {
        $this->query()->whereKey($accountId)->delete();
    }

    public function expiringBefore(DateTimeInterface $moment): iterable
    {
        foreach ($this->query()->whereNull('revoked_at')->where('access_token_expires_at', '<=', $moment)->lazyById() as $account) {
            yield $account->toData();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenAttributes(AccessToken $token): array
    {
        return [
            'scopes' => $token->scopes,
            'access_token' => $token->accessToken,
            'access_token_expires_at' => $token->expiresAt,
            'refresh_token' => $token->refreshToken,
            'refresh_token_expires_at' => $token->refreshExpiresAt,
        ];
    }

    /**
     * @return Builder<TikTokAccount>
     */
    private function query(): Builder
    {
        return $this->model::query();
    }
}
