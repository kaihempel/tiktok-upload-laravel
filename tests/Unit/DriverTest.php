<?php

declare(strict_types=1);

use KaiHempel\TikTokUpload\Data\MediaType;
use KaiHempel\TikTokUpload\Drivers\TikTokDriver;

it('describes its capabilities', function (): void {
    $driver = app(TikTokDriver::class);
    $capabilities = $driver->capabilities();

    expect($driver->key())->toBe('tiktok')
        ->and($capabilities->directPublish)->toBeFalse()
        ->and($capabilities->draftUpload)->toBeTrue()
        ->and($capabilities->captionInDraft)->toBeFalse()
        ->and($capabilities->video)->toBeTrue()
        ->and($capabilities->photos)->toBeTrue()
        ->and($capabilities->maxPhotos)->toBe(35)
        ->and($capabilities->maxCaptionLength)->toBe(4000)
        ->and($driver->capabilitiesFor(MediaType::Photo)->captionInDraft)->toBeTrue();
});

it('encodes account and publish id into the remote id', function (): void {
    $remoteId = TikTokDriver::remoteId('01JABC', 'v_inbox_file~v2.123');

    expect($remoteId)->toBe('01JABC:v_inbox_file~v2.123')
        ->and(TikTokDriver::parseRemoteId($remoteId))->toBe(['01JABC', 'v_inbox_file~v2.123']);
});

it('rejects malformed remote ids', function (string $remoteId): void {
    TikTokDriver::parseRemoteId($remoteId);
})->with(['no-separator', ':missing-account', 'missing-publish:'])->throws(InvalidArgumentException::class);
