<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;
use KaiHempel\TikTokUpload\Data\UserProfile;
use KaiHempel\TikTokUpload\OAuth\AccessToken;

interface TokenRepository
{
    public function find(string $accountId): ?TikTokAccountData;

    /**
     * Stores a newly authorised account. Re-connecting an existing open_id
     * updates the stored record instead of creating a duplicate.
     */
    public function store(AccessToken $token, Model $owner, ?UserProfile $profile = null): TikTokAccountData;

    public function update(string $accountId, AccessToken $token): void;

    public function markRevoked(string $accountId): void;

    /**
     * Removes the account including all tokens and profile data (GDPR).
     */
    public function delete(string $accountId): void;

    /**
     * Active (not revoked) accounts whose access token expires before the given moment.
     *
     * @return iterable<TikTokAccountData>
     */
    public function expiringBefore(DateTimeInterface $moment): iterable;
}
