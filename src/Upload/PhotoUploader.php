<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Upload;

use KaiHempel\TikTokUpload\Contracts\MediaUrlProvider;
use KaiHempel\TikTokUpload\Data\MediaType;
use KaiHempel\TikTokUpload\Data\PublishStatus;
use KaiHempel\TikTokUpload\Data\UploadResult;
use KaiHempel\TikTokUpload\Exceptions\UnexpectedResponseException;
use KaiHempel\TikTokUpload\Http\TikTokHttpClient;
use KaiHempel\TikTokUpload\Media\PhotoItem;
use KaiHempel\TikTokUpload\Media\PhotoSet;

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
