<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;

/**
 * @property string $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $open_id
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property list<string> $scopes
 * @property string $access_token
 * @property Carbon $access_token_expires_at
 * @property string $refresh_token
 * @property Carbon $refresh_token_expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TikTokAccount extends Model
{
    use HasUlids;

    protected $table = 'tiktok_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function toData(): TikTokAccountData
    {
        return new TikTokAccountData(
            id: $this->id,
            ownerType: $this->owner_type,
            ownerId: (string) $this->owner_id,
            openId: $this->open_id,
            displayName: $this->display_name,
            avatarUrl: $this->avatar_url,
            scopes: $this->scopes,
            accessToken: $this->access_token,
            accessTokenExpiresAt: CarbonImmutable::instance($this->access_token_expires_at),
            refreshToken: $this->refresh_token,
            refreshTokenExpiresAt: CarbonImmutable::instance($this->refresh_token_expires_at),
            revokedAt: $this->revoked_at !== null ? CarbonImmutable::instance($this->revoked_at) : null,
        );
    }
}
