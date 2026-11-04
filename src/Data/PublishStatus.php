<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Data;

use KaiHempel\TikTokUpload\Exceptions\UnexpectedResponseException;

enum PublishStatus: string
{
    case ProcessingUpload = 'PROCESSING_UPLOAD';
    case ProcessingDownload = 'PROCESSING_DOWNLOAD';
    case SendToUserInbox = 'SEND_TO_USER_INBOX';
    case PublishComplete = 'PUBLISH_COMPLETE';
    case Failed = 'FAILED';

    public static function fromTikTok(string $status): self
    {
        return self::tryFrom(strtoupper($status))
            ?? throw new UnexpectedResponseException("Unknown TikTok publish status [{$status}].", 'unknown_status');
    }

    /**
     * TikTok is still working on the upload; polling has to continue.
     */
    public function isProcessing(): bool
    {
        return $this === self::ProcessingUpload || $this === self::ProcessingDownload;
    }

    /**
     * Final state in upload (inbox) mode. SEND_TO_USER_INBOX counts as final.
     */
    public function isFinal(): bool
    {
        return ! $this->isProcessing();
    }

    public function isSuccessful(): bool
    {
        return $this === self::SendToUserInbox || $this === self::PublishComplete;
    }
}
