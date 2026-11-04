<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Contracts;

use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\VideoFile;

/**
 * Hook that runs before validation, e.g. to transcode videos with ffmpeg or
 * to convert PNG images into JPEG/WebP.
 */
interface MediaTransformer
{
    public function transformVideo(VideoFile $video): VideoFile;

    public function transformPhotos(PhotoSet $photos): PhotoSet;
}
