<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Data;

enum MediaType: string
{
    case Video = 'video';
    case Photo = 'photo';
}
