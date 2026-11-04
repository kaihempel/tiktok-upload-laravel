<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Data;

enum MediaType: string
{
    case Video = 'video';
    case Photo = 'photo';
}
