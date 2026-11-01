<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

final readonly class StatusResult
{
    /**
     * @param  list<string>  $publicPostIds
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $publishId,
        public PublishStatus $status,
        public ?string $failReason = null,
        public array $publicPostIds = [],
        public ?int $uploadedBytes = null,
        public ?int $downloadedBytes = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(string $publishId, array $data): self
    {
        $postIds = $data['publicaly_available_post_id'] ?? $data['publicly_available_post_id'] ?? [];

        return new self(
            publishId: $publishId,
            status: PublishStatus::fromTikTok((string) ($data['status'] ?? '')),
            failReason: isset($data['fail_reason']) && $data['fail_reason'] !== '' ? (string) $data['fail_reason'] : null,
            publicPostIds: array_values(array_map(strval(...), is_array($postIds) ? $postIds : [])),
            uploadedBytes: isset($data['uploaded_bytes']) ? (int) $data['uploaded_bytes'] : null,
            downloadedBytes: isset($data['downloaded_bytes']) ? (int) $data['downloaded_bytes'] : null,
            raw: $data,
        );
    }
}
