<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Upload;

use Kuweh\TikTokUpload\Data\StatusResult;
use Kuweh\TikTokUpload\Http\TikTokHttpClient;

class StatusFetcher
{
    public const string PATH = '/v2/post/publish/status/fetch/';

    public function __construct(
        private readonly TikTokHttpClient $client,
    ) {}

    public function fetch(string $accountId, string $publishId): StatusResult
    {
        $data = $this->client->post($accountId, self::PATH, ['publish_id' => $publishId]);

        return StatusResult::fromResponse($publishId, $data);
    }
}
