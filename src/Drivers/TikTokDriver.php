<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Drivers;

use InvalidArgumentException;
use Kuweh\TikTokUpload\Contracts\PlatformDriver;
use Kuweh\TikTokUpload\Data\Capabilities;
use Kuweh\TikTokUpload\Data\MediaType;
use Kuweh\TikTokUpload\Data\PublishRequest;
use Kuweh\TikTokUpload\Data\PublishResult;
use Kuweh\TikTokUpload\Data\PublishState;
use Kuweh\TikTokUpload\Data\PublishStatus;
use Kuweh\TikTokUpload\Media\PhotoSet;
use Kuweh\TikTokUpload\TikTokManager;

/**
 * PlatformDriver implementation used by the social media manager.
 *
 * Remote ids have the form "<accountId>:<publishId>" because status() only
 * receives the remote id but TikTok needs the account's access token.
 */
class TikTokDriver implements PlatformDriver
{
    public function __construct(
        private readonly TikTokManager $tiktok,
        private readonly int $maxPhotos = 35,
        private readonly int $maxCaptionLength = 4000,
    ) {}

    public function key(): string
    {
        return 'tiktok';
    }

    /**
     * General capabilities. captionInDraft is false because videos in the
     * inbox cannot carry a caption; see capabilitiesFor() for photos.
     */
    public function capabilities(): Capabilities
    {
        return $this->capabilitiesFor(MediaType::Video);
    }

    public function capabilitiesFor(MediaType $type): Capabilities
    {
        return new Capabilities(
            directPublish: false,
            draftUpload: true,
            captionInDraft: $type === MediaType::Photo,
            video: true,
            photos: true,
            maxPhotos: $this->maxPhotos,
            maxCaptionLength: $this->maxCaptionLength,
        );
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $client = $this->tiktok->forAccount($request->accountId);

        if ($request->media instanceof PhotoSet) {
            $photos = $request->media;

            if ($request->caption !== null && $photos->getDescription() === null) {
                $photos = $photos->description($request->caption);
            }

            if (isset($request->options['title']) && is_string($request->options['title'])) {
                $photos = $photos->title($request->options['title']);
            }

            $result = $client->uploadPhotos($photos, $request->reference);
        } else {
            $source = $request->options['source'] ?? null;

            $result = $client->uploadVideo(
                $request->media,
                is_string($source) ? $source : null,
                $request->reference,
                $request->caption,
            );
        }

        return new PublishResult(
            remoteId: self::remoteId($result->accountId, $result->publishId),
            state: PublishState::fromPublishStatus($result->status),
            platformStatus: $result->status->value,
            reference: $result->reference,
            captionForUser: $result->captionForUser,
            requiresUserAction: true,
        );
    }

    public function status(string $remoteId): PublishResult
    {
        [$accountId, $publishId] = self::parseRemoteId($remoteId);

        $status = $this->tiktok->forAccount($accountId)->status($publishId);

        return new PublishResult(
            remoteId: $remoteId,
            state: PublishState::fromPublishStatus($status->status),
            platformStatus: $status->status->value,
            requiresUserAction: $status->status === PublishStatus::SendToUserInbox,
            error: $status->failReason,
        );
    }

    public static function remoteId(string $accountId, string $publishId): string
    {
        return $accountId.':'.$publishId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function parseRemoteId(string $remoteId): array
    {
        $parts = explode(':', $remoteId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Invalid TikTok remote id [{$remoteId}], expected '<accountId>:<publishId>'.");
        }

        return [$parts[0], $parts[1]];
    }
}
