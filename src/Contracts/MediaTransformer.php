<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Contracts;

use Kuweh\TikTokUpload\Media\PhotoSet;
use Kuweh\TikTokUpload\Media\VideoFile;

/**
 * Hook that runs before validation, e.g. to transcode videos with ffmpeg or
 * to convert PNG images into JPEG/WebP.
 */
interface MediaTransformer
{
    public function transformVideo(VideoFile $video): VideoFile;

    public function transformPhotos(PhotoSet $photos): PhotoSet;
}
