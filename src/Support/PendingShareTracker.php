<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Counts inbox uploads per account in a sliding 24 h window, so the package can
 * warn before TikTok rejects with spam_risk_too_many_pending_share.
 */
final class PendingShareTracker
{
    private const int WINDOW_SECONDS = 86400;

    public function __construct(
        private readonly Cache $cache,
        private readonly int $limit = 5,
    ) {}

    public function record(string $accountId): void
    {
        $timestamps = $this->timestamps($accountId);
        $timestamps[] = Carbon::now()->getTimestamp();

        $this->cache->put($this->key($accountId), $timestamps, self::WINDOW_SECONDS);
    }

    public function count(string $accountId): int
    {
        return count($this->timestamps($accountId));
    }

    public function isLimitReached(string $accountId): bool
    {
        return $this->count($accountId) >= $this->limit;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function clear(string $accountId): void
    {
        $this->cache->forget($this->key($accountId));
    }

    /**
     * @return list<int>
     */
    private function timestamps(string $accountId): array
    {
        $stored = $this->cache->get($this->key($accountId), []);
        $threshold = Carbon::now()->getTimestamp() - self::WINDOW_SECONDS;

        return array_values(array_filter(
            is_array($stored) ? array_map(intval(...), $stored) : [],
            static fn (int $timestamp): bool => $timestamp > $threshold,
        ));
    }

    private function key(string $accountId): string
    {
        return 'tiktok-upload:pending-shares:'.$accountId;
    }
}
