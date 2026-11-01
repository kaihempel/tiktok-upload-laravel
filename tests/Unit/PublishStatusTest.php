<?php

declare(strict_types=1);

use Kuweh\TikTokUpload\Data\PublishState;
use Kuweh\TikTokUpload\Data\PublishStatus;
use Kuweh\TikTokUpload\Data\StatusResult;
use Kuweh\TikTokUpload\Exceptions\UnexpectedResponseException;

it('maps TikTok status strings', function (string $raw, PublishStatus $status, bool $final, bool $successful, PublishState $state): void {
    $mapped = PublishStatus::fromTikTok($raw);

    expect($mapped)->toBe($status)
        ->and($mapped->isFinal())->toBe($final)
        ->and($mapped->isProcessing())->toBe(! $final)
        ->and($mapped->isSuccessful())->toBe($successful)
        ->and(PublishState::fromPublishStatus($mapped))->toBe($state);
})->with([
    ['PROCESSING_UPLOAD', PublishStatus::ProcessingUpload, false, false, PublishState::Pending],
    ['PROCESSING_DOWNLOAD', PublishStatus::ProcessingDownload, false, false, PublishState::Pending],
    ['SEND_TO_USER_INBOX', PublishStatus::SendToUserInbox, true, true, PublishState::Draft],
    ['PUBLISH_COMPLETE', PublishStatus::PublishComplete, true, true, PublishState::Published],
    ['FAILED', PublishStatus::Failed, true, false, PublishState::Failed],
    ['send_to_user_inbox', PublishStatus::SendToUserInbox, true, true, PublishState::Draft],
]);

it('rejects unknown status values', function (): void {
    PublishStatus::fromTikTok('SOMETHING_NEW');
})->throws(UnexpectedResponseException::class);

it('builds a status result from fixtures', function (): void {
    $result = StatusResult::fromResponse('pub-1', tiktokFixture('status_publish_complete')['data']);

    expect($result->status)->toBe(PublishStatus::PublishComplete)
        ->and($result->publicPostIds)->toBe(['7291234567890123456'])
        ->and($result->uploadedBytes)->toBe(10000);

    $failed = StatusResult::fromResponse('pub-2', tiktokFixture('status_failed')['data']);

    expect($failed->status)->toBe(PublishStatus::Failed)
        ->and($failed->failReason)->toBe('file_format_check_failed');
});
