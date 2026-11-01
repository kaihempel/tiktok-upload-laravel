<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Upload;

use Kuweh\TikTokUpload\Contracts\MediaUrlProvider;
use Kuweh\TikTokUpload\Data\MediaType;
use Kuweh\TikTokUpload\Data\PublishStatus;
use Kuweh\TikTokUpload\Data\UploadResult;
use Kuweh\TikTokUpload\Exceptions\UnexpectedResponseException;
use Kuweh\TikTokUpload\Http\TikTokHttpClient;
use Kuweh\TikTokUpload\Media\PhotoItem;
use Kuweh\TikTokUpload\Media\PhotoSet;

/**
 * Uploads a photo slideshow into the creator's inbox (post_mode MEDIA_UPLOAD).
 * Photos can only be transferred via PULL_FROM_URL.
 */
class PhotoUploader
{
    public const string INIT_PATH = '/v2/post/publish/content/init/';

    public const string POST_MODE = 'MEDIA_UPLOAD';

    public function __construct(
        private readonly TikTokHttpClient $client,
        private readonly MediaUrlProvider $urls,
    ) {}

    public function upload(string $accountId, PhotoSet $photos, ?string $reference = null): UploadResult
    {
        $urls = array_map(
            fn (PhotoItem $photo): string => $photo->isRemote()
                ? (string) $photo->url
                : $this->urls->urlFor((string) $photo->disk, (string) $photo->path),
            $photos->photos(),
        );

        $data = $this->client->post($accountId, static::INIT_PATH, $this->initPayload($photos, $urls));
        $publishId = $data['publish_id'] ?? null;

        if (! is_string($publishId) || $publishId === '') {
            throw new UnexpectedResponseException('TikTok did not return a publish_id.', 'missing_publish_id');
        }

        return new UploadResult($publishId, PublishStatus::ProcessingDownload, $accountId, $reference, null, MediaType::Photo, $urls);
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, mixed>
     */
    protected function initPayload(PhotoSet $photos, array $urls): array
    {
        return [
            'post_mode' => static::POST_MODE,
            'media_type' => 'PHOTO',
            'post_info' => array_filter([
                'title' => $photos->getTitle(),
                'description' => $photos->getDescription(),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => $photos->getCoverIndex(),
                'photo_images' => $urls,
            ],
        ];
    }
}
