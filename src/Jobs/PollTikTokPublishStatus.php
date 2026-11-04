<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use KaiHempel\TikTokUpload\Contracts\HasTikTokAccount;
use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use KaiHempel\TikTokUpload\Contracts\RevocableMediaUrlProvider;
use KaiHempel\TikTokUpload\Data\PublishStatus;
use KaiHempel\TikTokUpload\Data\StatusResult;
use KaiHempel\TikTokUpload\Events\TikTokPostPublished;
use KaiHempel\TikTokUpload\Events\TikTokUploadFailed;
use KaiHempel\TikTokUpload\Events\TikTokUploadSentToInbox;
use KaiHempel\TikTokUpload\Events\TikTokUploadTimedOut;
use KaiHempel\TikTokUpload\Exceptions\RateLimitException;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use KaiHempel\TikTokUpload\Jobs\Middleware\RateLimitedPerTikTokAccount;
use KaiHempel\TikTokUpload\TikTokManager;
use Throwable;

/**
 * Polls /v2/post/publish/status/fetch/ by releasing itself until a final state
 * is reached or max_attempts is exhausted (→ TikTokUploadTimedOut).
 *
 * With polling.long_term.enabled a second, low frequency polling phase is
 * started after SEND_TO_USER_INBOX to detect PUBLISH_COMPLETE.
 */
class PollTikTokPublishStatus implements HasTikTokAccount, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @param  list<string>  $mediaUrls
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $publishId,
        public readonly ?string $reference = null,
        public readonly ?string $captionForUser = null,
        public readonly array $mediaUrls = [],
        public readonly bool $longTerm = false,
    ) {
        // Every release() counts as an attempt, the limit is enforced in handle().
        $this->tries = $this->maxAttempts() + 10;

        $this->onConnection(Config::get('tiktok-upload.queue.connection'));
        $this->onQueue(Config::get('tiktok-upload.queue.queue'));
    }

    public function tikTokAccountId(): string
    {
        return $this->accountId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimitedPerTikTokAccount];
    }

    public function handle(TikTokManager $tiktok): void
    {
        try {
            $status = $tiktok->forAccount($this->accountId)->status($this->publishId);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (TikTokException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }

            $this->continuePolling();

            return;
        }

        $this->longTerm ? $this->handleLongTerm($status) : $this->handleStatus($status);
    }

    public function failed(?Throwable $exception = null): void
    {
        $code = $exception instanceof TikTokException ? (string) ($exception->errorCode ?? 'unknown') : 'job_failed';

        app('tiktok-upload.logger')->error('Polling the TikTok publish status failed.', [
            'account_id' => $this->accountId,
            'publish_id' => $this->publishId,
            'code' => $code,
            'log_id' => $exception instanceof TikTokException ? $exception->logId : null,
        ]);

        event(new TikTokUploadFailed($this->accountId, $this->publishId, $this->reference, $code, $exception?->getMessage() ?? 'Polling failed.'));
    }

    public function maxAttempts(): int
    {
        return $this->longTerm
            ? (int) Config::get('tiktok-upload.polling.long_term.max_attempts', 48)
            : (int) Config::get('tiktok-upload.polling.max_attempts', 80);
    }

    public function interval(): int
    {
        return $this->longTerm
            ? (int) Config::get('tiktok-upload.polling.long_term.interval_seconds', 3600)
            : (int) Config::get('tiktok-upload.polling.interval_seconds', 15);
    }

    private function handleStatus(StatusResult $status): void
    {
        match ($status->status) {
            PublishStatus::ProcessingUpload, PublishStatus::ProcessingDownload => $this->continuePolling(),
            PublishStatus::SendToUserInbox => $this->sentToInbox(),
            PublishStatus::PublishComplete => $this->published($status),
            PublishStatus::Failed => $this->failedAtTikTok($status),
        };
    }

    private function handleLongTerm(StatusResult $status): void
    {
        match ($status->status) {
            PublishStatus::PublishComplete => $this->published($status),
            PublishStatus::Failed => $this->failedAtTikTok($status),
            default => $this->attempts() < $this->maxAttempts() ? $this->release($this->interval()) : null,
        };
    }

    private function continuePolling(): void
    {
        if ($this->attempts() >= $this->maxAttempts()) {
            event(new TikTokUploadTimedOut($this->accountId, $this->publishId, $this->reference));

            return;
        }

        $this->release($this->interval());
    }

    private function sentToInbox(): void
    {
        event(new TikTokUploadSentToInbox($this->accountId, $this->publishId, $this->reference, $this->captionForUser));

        if (Config::get('tiktok-upload.media_url.invalidate_after_inbox', false)) {
            $this->revokeMediaUrls();
        }

        if (Config::get('tiktok-upload.polling.long_term.enabled', false)) {
            static::dispatch($this->accountId, $this->publishId, $this->reference, $this->captionForUser, $this->mediaUrls, true)
                ->delay((int) Config::get('tiktok-upload.polling.long_term.interval_seconds', 3600));
        }
    }

    private function published(StatusResult $status): void
    {
        event(new TikTokPostPublished($this->accountId, $this->publishId, $this->reference, $status->publicPostIds));
    }

    private function failedAtTikTok(StatusResult $status): void
    {
        event(new TikTokUploadFailed(
            $this->accountId,
            $this->publishId,
            $this->reference,
            $status->failReason ?? 'failed',
            'TikTok reported FAILED'.($status->failReason !== null ? ": {$status->failReason}" : '.'),
        ));

        $this->revokeMediaUrls();
    }

    private function revokeMediaUrls(): void
    {
        $provider = app(MediaUrlProvider::class);

        if (! $provider instanceof RevocableMediaUrlProvider) {
            return;
        }

        foreach ($this->mediaUrls as $url) {
            $provider->revoke($url);
        }
    }
}
