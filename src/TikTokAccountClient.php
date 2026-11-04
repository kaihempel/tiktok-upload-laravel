<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload;

use Illuminate\Contracts\Events\Dispatcher;
use KaiHempel\TikTokUpload\Contracts\MediaTransformer;
use KaiHempel\TikTokUpload\Data\StatusResult;
use KaiHempel\TikTokUpload\Data\TikTokAccountData;
use KaiHempel\TikTokUpload\Data\UploadResult;
use KaiHempel\TikTokUpload\Events\TikTokUploadInitiated;
use KaiHempel\TikTokUpload\Exceptions\PendingShareLimitReachedException;
use KaiHempel\TikTokUpload\Http\TikTokHttpClient;
use KaiHempel\TikTokUpload\Media\MediaValidator;
use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\VideoFile;
use KaiHempel\TikTokUpload\OAuth\OAuthService;
use KaiHempel\TikTokUpload\Support\PendingShareTracker;
use KaiHempel\TikTokUpload\Upload\PhotoUploader;
use KaiHempel\TikTokUpload\Upload\StatusFetcher;
use KaiHempel\TikTokUpload\Upload\VideoUploader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * All operations for a single connected TikTok account.
 */
class TikTokAccountClient
{
    /**
     * @param  array{default_source?: string, track_pending_shares?: bool, block_pending_shares?: bool}  $options
     */
    public function __construct(
        public readonly string $accountId,
        private readonly TikTokHttpClient $client,
        private readonly OAuthService $oauth,
        private readonly VideoUploader $videos,
        private readonly PhotoUploader $photos,
        private readonly StatusFetcher $statuses,
        private readonly MediaValidator $validator,
        private readonly PendingShareTracker $pendingShares,
        private readonly Dispatcher $events,
        private readonly ?MediaTransformer $transformer = null,
        private readonly array $options = [],
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function account(): TikTokAccountData
    {
        return $this->client->account($this->accountId);
    }

    /**
     * Uploads a video into the inbox. The inbox endpoint does not accept any
     * text: $caption is only handed back as captionForUser.
     *
     * @param  string|null  $source  'file' (FILE_UPLOAD) or 'url' (PULL_FROM_URL), default from config
     */
    public function uploadVideo(VideoFile $video, ?string $source = null, ?string $reference = null, ?string $caption = null): UploadResult
    {
        $video = $this->transformer?->transformVideo($video) ?? $video;

        $this->validator->validateVideo($video);
        $this->guardPendingShares();

        $result = $this->videos->upload(
            $this->accountId,
            $video,
            $source ?? (string) ($this->options['default_source'] ?? VideoUploader::SOURCE_FILE),
            $reference,
            $caption,
        );

        return $this->initiated($result);
    }

    public function uploadPhotos(PhotoSet $photos, ?string $reference = null): UploadResult
    {
        $photos = $this->transformer?->transformPhotos($photos) ?? $photos;

        $this->validator->validatePhotos($photos);
        $this->guardPendingShares();

        return $this->initiated($this->photos->upload($this->accountId, $photos, $reference));
    }

    public function status(string $publishId): StatusResult
    {
        return $this->statuses->fetch($this->accountId, $publishId);
    }

    public function refreshToken(): TikTokAccountData
    {
        return $this->oauth->refresh($this->accountId);
    }

    /**
     * Revokes the token at TikTok. With $deleteData all stored data is removed.
     */
    public function disconnect(bool $deleteData = false): void
    {
        $this->oauth->revoke($this->accountId, $deleteData);
        $this->pendingShares->clear($this->accountId);
    }

    public function pendingShareCount(): int
    {
        return $this->pendingShares->count($this->accountId);
    }

    private function guardPendingShares(): void
    {
        if (! ($this->options['track_pending_shares'] ?? true) || ! $this->pendingShares->isLimitReached($this->accountId)) {
            return;
        }

        $message = sprintf(
            'TikTok account [%s] has reached %d inbox uploads within 24 hours, TikTok will most likely reject further uploads.',
            $this->accountId,
            $this->pendingShares->limit(),
        );

        if ($this->options['block_pending_shares'] ?? false) {
            throw new PendingShareLimitReachedException($message, 'local_pending_share_limit');
        }

        $this->logger->warning($message);
    }

    private function initiated(UploadResult $result): UploadResult
    {
        if ($this->options['track_pending_shares'] ?? true) {
            $this->pendingShares->record($this->accountId);
        }

        $this->events->dispatch(new TikTokUploadInitiated($result->accountId, $result->publishId, $result->reference, $result->mediaType));

        return $result;
    }
}
