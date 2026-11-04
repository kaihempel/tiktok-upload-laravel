<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use KaiHempel\TikTokUpload\OAuth\OAuthService;

/**
 * Keeps access tokens fresh even without upload activity. Recommended to run
 * hourly via the scheduler: Schedule::command('tiktok:refresh-tokens')->hourly();
 */
class RefreshTikTokTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens
        {--minutes= : Refresh tokens expiring within this many minutes (default: refresh_before_expiry_minutes + 60)}';

    protected $description = 'Refresh TikTok access tokens that are about to expire';

    public function handle(TokenRepository $tokens, OAuthService $oauth): int
    {
        $minutes = $this->option('minutes');
        $minutes = is_numeric($minutes)
            ? (int) $minutes
            : (int) config('tiktok-upload.refresh_before_expiry_minutes', 30) + 60;

        $refreshed = 0;
        $failed = 0;

        foreach ($tokens->expiringBefore(Carbon::now()->addMinutes($minutes)) as $account) {
            try {
                $oauth->refresh($account);
                $refreshed++;
                $this->line("Refreshed TikTok account [{$account->id}].");
            } catch (TikTokException $e) {
                $failed++;
                $this->error("Refreshing TikTok account [{$account->id}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Refreshed {$refreshed} account(s), {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
