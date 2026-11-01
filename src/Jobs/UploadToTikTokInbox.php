<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Kuweh\TikTokUpload\Contracts\HasTikTokAccount;
use Kuweh\TikTokUpload\Events\TikTokUploadFailed;
use Kuweh\TikTokUpload\Exceptions\RateLimitException;
use Kuweh\TikTokUpload\Exceptions\TikTokException;
use Kuweh\TikTokUpload\Exceptions\UploadFailedException;
use Kuweh\TikTokUpload\Jobs\Middleware\RateLimitedPerTikTokAccount;
use Kuweh\TikTokUpload\Media\PhotoSet;
use Kuweh\TikTokUpload\Media\VideoFile;
use Kuweh\TikTokUpload\TikTokManager;
use Throwable;

/**
 * Uploads a video or photo set into the TikTok inbox and starts status polling.
 *
 * Retryable: network errors, 5xx, rate_limit_exceeded (exponential backoff).
 * Not retryable: validation, scope, URL verification, quota limits.
 */
class UploadToTikTokInbox implements HasTikTokAccount, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @var list<int>
     */
    public array $backoff;

    /**
     * @param  string|null  $source  'file' (FILE_UPLOAD) or 'url' (PULL_FROM_URL), default from config
     */
    public function __construct(
        public readonly string $accountId,
        public readonly VideoFile|PhotoSet $media,
        public readonly ?string $reference = null,
        public readonly ?string $caption = null,
        public readonly ?string $source = null,
    ) {
        $this->tries = (int) Config::get('tiktok-upload.queue.tries', 5);
        $this->backoff = array_values(array_map(intval(...), (array) Config::get('tiktok-upload.queue.backoff', [10, 30, 90, 270])));

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
        $client = $tiktok->forAccount($this->accountId);

        try {
            $result = $this->media instanceof VideoFile
                ? $client->uploadVideo($this->media, $this->source, $this->reference, $this->caption)
                : $client->uploadPhotos($this->media, $this->reference);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (TikTokException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            $this->fail($e);

            return;
        }

        PollTikTokPublishStatus::dispatch(
            $result->accountId,
            $result->publishId,
            $result->reference,
            $result->captionForUser,
            $result->mediaUrls,
        )->delay((int) Config::get('tiktok-upload.polling.interval_seconds', 15));
    }

    public function failed(?Throwable $exception = null): void
    {
        $code = $exception instanceof TikTokException ? (string) ($exception->errorCode ?? 'unknown') : 'job_failed';

        app('tiktok-upload.logger')->error('TikTok inbox upload failed.', [
            'account_id' => $this->accountId,
            'reference' => $this->reference,
            'code' => $code,
            'log_id' => $exception instanceof TikTokException ? $exception->logId : null,
            'message' => $exception?->getMessage(),
        ]);

        event(new TikTokUploadFailed(
            accountId: $this->accountId,
            publishId: $exception instanceof UploadFailedException ? $exception->publishId : null,
            reference: $this->reference,
            errorCode: $code,
            message: $exception?->getMessage() ?? 'The upload job failed.',
        ));
    }
}
