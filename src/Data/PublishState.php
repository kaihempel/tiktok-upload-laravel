<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

/**
 * Platform independent state used by the PlatformDriver contract.
 */
enum PublishState: string
{
    case Pending = 'pending';
    case Draft = 'draft';
    case Published = 'published';
    case Failed = 'failed';

    public static function fromPublishStatus(PublishStatus $status): self
    {
        return match ($status) {
            PublishStatus::ProcessingUpload, PublishStatus::ProcessingDownload => self::Pending,
            PublishStatus::SendToUserInbox => self::Draft,
            PublishStatus::PublishComplete => self::Published,
            PublishStatus::Failed => self::Failed,
        };
    }
}
