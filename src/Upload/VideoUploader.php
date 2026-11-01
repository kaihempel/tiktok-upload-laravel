<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Upload;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Kuweh\TikTokUpload\Contracts\MediaUrlProvider;
use Kuweh\TikTokUpload\Data\MediaType;
use Kuweh\TikTokUpload\Data\PublishStatus;
use Kuweh\TikTokUpload\Data\UploadResult;
use Kuweh\TikTokUpload\Exceptions\UnexpectedResponseException;
use Kuweh\TikTokUpload\Exceptions\UploadFailedException;
use Kuweh\TikTokUpload\Http\TikTokHttpClient;
use Kuweh\TikTokUpload\Media\VideoFile;

/**
 * Uploads a video into the creator's TikTok inbox (upload mode).
 *
 * The endpoint is encapsulated in INIT_PATH / initPayload() so that a
 * DirectPostUploader (/v2/post/publish/video/init/) can be added later.
 */
class VideoUploader
{
    public const string INIT_PATH = '/v2/post/publish/inbox/video/init/';

    public const string SOURCE_FILE = 'file';

    public const string SOURCE_URL = 'url';

    private const int MAX_REINITIALIZATIONS = 2;

    /**
     * @param  array{timeout?: int, chunk_retries?: int, chunk_retry_backoff_ms?: int, upload_url_ttl_seconds?: int}  $config
     */
    public function __construct(
        private readonly TikTokHttpClient $client,
        private readonly HttpFactory $http,
        private readonly ChunkPlanner $planner,
        private readonly MediaUrlProvider $urls,
        private readonly array $config = [],
    ) {}

    public function upload(
        string $accountId,
        VideoFile $video,
        string $source = self::SOURCE_FILE,
        ?string $reference = null,
        ?string $captionForUser = null,
    ): UploadResult {
        $source = $video->isRemote() ? self::SOURCE_URL : $source;

        return match ($source) {
            self::SOURCE_FILE => $this->uploadFile($accountId, $video, $reference, $captionForUser),
            self::SOURCE_URL => $this->uploadFromUrl($accountId, $video, $reference, $captionForUser),
            default => throw new InvalidArgumentException("Unknown upload source [{$source}], expected 'file' or 'url'."),
        };
    }

    private function uploadFile(string $accountId, VideoFile $video, ?string $reference, ?string $captionForUser): UploadResult
    {
        $plan = $this->planner->plan((int) $video->size());
        $reinitializations = 0;

        while (true) {
            $data = $this->init($accountId, $plan->toSourceInfo());
            $publishId = $this->publishId($data);
            $uploadUrl = $data['upload_url'] ?? null;

            if (! is_string($uploadUrl) || $uploadUrl === '') {
                throw new UnexpectedResponseException('TikTok did not return an upload_url.', 'missing_upload_url');
            }

            try {
                $this->transferChunks($uploadUrl, $video, $plan, Carbon::now());
            } catch (UploadUrlExpired $expired) {
                if (++$reinitializations > self::MAX_REINITIALIZATIONS) {
                    throw new UploadFailedException('The TikTok upload URL expired repeatedly.', 'upload_url_expired', publishId: $publishId, previous: $expired, retryable: true);
                }

                continue;
            }

            return new UploadResult($publishId, PublishStatus::ProcessingUpload, $accountId, $reference, $captionForUser, MediaType::Video);
        }
    }

    private function uploadFromUrl(string $accountId, VideoFile $video, ?string $reference, ?string $captionForUser): UploadResult
    {
        $url = $video->isRemote()
            ? (string) $video->url
            : $this->urls->urlFor((string) $video->disk, (string) $video->path);

        $data = $this->init($accountId, ['source' => 'PULL_FROM_URL', 'video_url' => $url]);

        return new UploadResult($this->publishId($data), PublishStatus::ProcessingDownload, $accountId, $reference, $captionForUser, MediaType::Video, [$url]);
    }

    /**
     * @param  array<string, mixed>  $sourceInfo
     * @return array<string, mixed>
     */
    protected function init(string $accountId, array $sourceInfo): array
    {
        return $this->client->post($accountId, static::INIT_PATH, $this->initPayload($sourceInfo));
    }

    /**
     * @param  array<string, mixed>  $sourceInfo
     * @return array<string, mixed>
     */
    protected function initPayload(array $sourceInfo): array
    {
        return ['source_info' => $sourceInfo];
    }

    /**
     * Streams the file chunk by chunk (never loading it completely into memory).
     */
    private function transferChunks(string $uploadUrl, VideoFile $video, ChunkPlan $plan, Carbon $initiatedAt): void
    {
        $stream = $video->readStream();
        $ttl = (int) ($this->config['upload_url_ttl_seconds'] ?? 3300);

        try {
            foreach ($plan->chunks() as $chunk) {
                if ($initiatedAt->diffInSeconds(Carbon::now(), true) >= $ttl) {
                    throw new UploadUrlExpired;
                }

                $body = $this->read($stream, $chunk['length']);

                if (strlen($body) !== $chunk['length']) {
                    throw new UploadFailedException("Could not read chunk #{$chunk['index']} of [{$video->description()}].", 'read_failed');
                }

                $this->putChunk($uploadUrl, $body, $video->contentType(), $chunk['start'], $chunk['end'], $plan->videoSize);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function putChunk(string $url, string $body, string $contentType, int $start, int $end, int $size): void
    {
        $retries = (int) ($this->config['chunk_retries'] ?? 3);
        $backoff = (int) ($this->config['chunk_retry_backoff_ms'] ?? 1000);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http
                    ->timeout((int) ($this->config['timeout'] ?? 300))
                    ->withHeaders([
                        'Content-Length' => (string) strlen($body),
                        'Content-Range' => "bytes {$start}-{$end}/{$size}",
                    ])
                    ->withBody($body, $contentType)
                    ->put($url);
            } catch (ConnectionException $e) {
                if (++$attempt > $retries) {
                    throw new UploadFailedException(
                        sprintf('Uploading bytes %d-%d failed: %s', $start, $end, $e->getMessage()),
                        'chunk_upload_failed',
                        previous: $e,
                        retryable: true,
                    );
                }

                Sleep::usleep($backoff * 1000 * (2 ** ($attempt - 1)));

                continue;
            }

            if ($response->successful()) {
                return;
            }

            if (in_array($response->status(), [403, 404, 410], true)) {
                throw new UploadUrlExpired;
            }

            $retryable = $response->serverError() || $response->status() === 429;

            if (! $retryable || ++$attempt > $retries) {
                throw new UploadFailedException(
                    sprintf('Uploading bytes %d-%d failed with HTTP %d.', $start, $end, $response->status()),
                    'chunk_upload_failed',
                    httpStatus: $response->status(),
                    retryable: $retryable,
                );
            }

            Sleep::usleep($backoff * 1000 * (2 ** ($attempt - 1)));
        }
    }

    /**
     * @param  resource  $stream
     */
    private function read($stream, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($stream)) {
            $part = fread($stream, max(1, min(1024 * 1024, $length - strlen($buffer))));

            if ($part === false) {
                break;
            }

            $buffer .= $part;
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publishId(array $data): string
    {
        $publishId = $data['publish_id'] ?? null;

        if (! is_string($publishId) || $publishId === '') {
            throw new UnexpectedResponseException('TikTok did not return a publish_id.', 'missing_publish_id');
        }

        return $publishId;
    }
}
