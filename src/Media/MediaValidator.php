<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media;

use KaiHempel\TikTokUpload\Contracts\MediaProbe;
use KaiHempel\TikTokUpload\Exceptions\MediaValidationException;
use KaiHempel\TikTokUpload\Support\Utf16;
use Throwable;

/**
 * Validates media against TikTok's requirements before any request is sent.
 * All violations are collected and reported at once.
 */
class MediaValidator
{
    /**
     * @param  array<string, mixed>  $video  config('tiktok-upload.video')
     * @param  array<string, mixed>  $photo  config('tiktok-upload.photo')
     */
    public function __construct(
        private readonly array $video = [],
        private readonly array $photo = [],
        private readonly ?MediaProbe $probe = null,
    ) {}

    /**
     * @throws MediaValidationException
     */
    public function validateVideo(VideoFile $video): void
    {
        $violations = $this->videoViolations($video);

        if ($violations !== []) {
            throw new MediaValidationException($violations);
        }
    }

    /**
     * @throws MediaValidationException
     */
    public function validatePhotos(PhotoSet $photos): void
    {
        $violations = $this->photoViolations($photos);

        if ($violations !== []) {
            throw new MediaValidationException($violations);
        }
    }

    /**
     * @return list<string>
     */
    public function videoViolations(VideoFile $video): array
    {
        $violations = [];
        $extension = $video->extension();
        $extensions = $this->list($this->video, 'extensions', ['mp4', 'webm', 'mov']);

        if ($video->isRemote()) {
            if ($extension !== '' && ! in_array($extension, $extensions, true)) {
                $violations[] = "The video format [{$extension}] is not supported (allowed: ".implode(', ', $extensions).').';
            }

            return $violations;
        }

        if (! $video->exists()) {
            return ["The video [{$video->description()}] does not exist."];
        }

        if (! in_array($extension, $extensions, true)) {
            $violations[] = "The video format [{$extension}] is not supported (allowed: ".implode(', ', $extensions).').';
        }

        $mime = $video->mimeType();
        $mimeTypes = $this->list($this->video, 'mime_types', ['video/mp4', 'video/webm', 'video/quicktime']);

        if ($mime !== null && $mime !== 'application/octet-stream' && ! in_array($mime, $mimeTypes, true)) {
            $violations[] = "The video MIME type [{$mime}] is not supported (allowed: ".implode(', ', $mimeTypes).').';
        }

        $size = (int) $video->size();
        $maxSize = $this->int($this->video, 'max_size_bytes', 4 * 1024 * 1024 * 1024);

        if ($size <= 0) {
            $violations[] = 'The video is empty.';
        } elseif ($size > $maxSize) {
            $violations[] = sprintf('The video is %s, the maximum is %s.', $this->bytes($size), $this->bytes($maxSize));
        }

        if ($this->probe !== null && $size > 0) {
            array_push($violations, ...$this->probeViolations($video));
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public function photoViolations(PhotoSet $photos): array
    {
        $violations = [];
        $count = $photos->count();
        $maxPhotos = $this->int($this->photo, 'max_photos', 35);

        if ($count === 0) {
            $violations[] = 'At least one photo is required.';
        } elseif ($count > $maxPhotos) {
            $violations[] = "A post may contain at most {$maxPhotos} photos, {$count} given.";
        }

        $cover = $photos->getCoverIndex();

        if ($count > 0 && ($cover < 0 || $cover >= $count)) {
            $violations[] = 'The cover index ['.$cover.'] is out of range (0–'.($count - 1).').';
        }

        $maxTitle = $this->int($this->photo, 'max_title_length', 90);
        $title = (string) $photos->getTitle();

        if (Utf16::length($title) > $maxTitle) {
            $violations[] = "The title must not exceed {$maxTitle} UTF-16 code units (".Utf16::length($title).' given).';
        }

        $maxDescription = $this->int($this->photo, 'max_description_length', 4000);
        $description = (string) $photos->getDescription();

        if (Utf16::length($description) > $maxDescription) {
            $violations[] = "The description must not exceed {$maxDescription} UTF-16 code units (".Utf16::length($description).' given).';
        }

        foreach ($photos->photos() as $index => $photo) {
            foreach ($this->singlePhotoViolations($photo) as $violation) {
                $violations[] = "Photo #{$index} ({$photo->description()}): {$violation}";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function singlePhotoViolations(PhotoItem $photo): array
    {
        $violations = [];
        $extension = $photo->extension();
        $extensions = $this->list($this->photo, 'extensions', ['jpg', 'jpeg', 'webp']);

        if ($extension === 'png') {
            $violations[] = 'PNG is not supported by TikTok, convert it to JPEG or WebP.';
        } elseif (($extension !== '' || ! $photo->isRemote()) && ! in_array($extension, $extensions, true)) {
            $violations[] = "The format [{$extension}] is not supported (allowed: ".implode(', ', $extensions).').';
        }

        if ($photo->isRemote()) {
            return $violations;
        }

        if (! $photo->exists()) {
            return ['The file does not exist.'];
        }

        $mime = $photo->mimeType();
        $mimeTypes = $this->list($this->photo, 'mime_types', ['image/jpeg', 'image/webp']);

        if ($mime !== null && $mime !== 'application/octet-stream' && ! in_array($mime, $mimeTypes, true) && $extension !== 'png') {
            $violations[] = "The MIME type [{$mime}] is not supported.";
        }

        $size = (int) $photo->size();
        $maxSize = $this->int($this->photo, 'max_size_bytes', 20 * 1024 * 1024);

        if ($size <= 0) {
            return [...$violations, 'The file is empty.'];
        }

        if ($size > $maxSize) {
            return [...$violations, sprintf('The file is %s, the maximum is %s.', $this->bytes($size), $this->bytes($maxSize))];
        }

        $dimensions = @getimagesizefromstring((string) $photo->contents());

        if ($dimensions === false) {
            return [...$violations, 'The file is not a readable image.'];
        }

        [$width, $height] = $dimensions;
        $maxLong = $this->int($this->photo, 'max_long_edge', 1920);
        $maxShort = $this->int($this->photo, 'max_short_edge', 1080);

        if (max($width, $height) > $maxLong || min($width, $height) > $maxShort) {
            $violations[] = "The resolution {$width}x{$height} exceeds 1080p ({$maxShort}x{$maxLong}).";
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function probeViolations(VideoFile $video): array
    {
        if ($this->probe === null) {
            return [];
        }

        try {
            $result = $this->probe->probe((string) $video->disk, (string) $video->path);
        } catch (Throwable $e) {
            return ['The video could not be analysed: '.$e->getMessage()];
        }

        $violations = [];
        $codecs = $this->list($this->video, 'codecs', ['h264', 'hevc', 'h265', 'vp8', 'vp9']);

        if ($result->codec !== null && ! in_array(strtolower($result->codec), $codecs, true)) {
            $violations[] = "The video codec [{$result->codec}] is not supported (allowed: ".implode(', ', $codecs).').';
        }

        $minFps = (float) ($this->video['min_fps'] ?? 23);
        $maxFps = (float) ($this->video['max_fps'] ?? 60);

        if ($result->fps !== null && ($result->fps < $minFps || $result->fps > $maxFps)) {
            $violations[] = sprintf('The frame rate %.2f fps is outside the allowed range (%s–%s fps).', $result->fps, $minFps, $maxFps);
        }

        $minDuration = (float) ($this->video['min_duration_seconds'] ?? 0);
        $maxDuration = (float) ($this->video['max_duration_seconds'] ?? 600);

        if ($result->durationSeconds !== null && ($result->durationSeconds < $minDuration || $result->durationSeconds > $maxDuration)) {
            $violations[] = sprintf('The duration %.1f s is outside the allowed range (%s–%s s).', $result->durationSeconds, $minDuration, $maxDuration);
        }

        $minDimension = $this->int($this->video, 'min_dimension', 360);
        $maxDimension = $this->int($this->video, 'max_dimension', 4096);

        if ($result->width !== null && $result->height !== null) {
            if (min($result->width, $result->height) < $minDimension || max($result->width, $result->height) > $maxDimension) {
                $violations[] = "The resolution {$result->width}x{$result->height} is outside the allowed range ({$minDimension}–{$maxDimension} px).";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $default
     * @return list<string>
     */
    private function list(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? $default;

        return is_array($value) ? array_values(array_map(static fn (mixed $v): string => strtolower((string) $v), $value)) : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function bytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}
