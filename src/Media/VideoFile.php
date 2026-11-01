<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Media;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Input object for a video. Either stored on a Laravel disk or already
 * reachable via a public URL (only usable with PULL_FROM_URL).
 */
final readonly class VideoFile
{
    private const array CONTENT_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    private function __construct(
        public ?string $disk,
        public ?string $path,
        public ?string $url,
    ) {}

    public static function fromDisk(string $disk, string $path): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('The video path must not be empty.');
        }

        return new self($disk, $path, null);
    }

    public static function fromUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("[{$url}] is not a valid URL.");
        }

        return new self(null, null, $url);
    }

    public function isRemote(): bool
    {
        return $this->url !== null;
    }

    public function extension(): string
    {
        $path = $this->path ?? (string) parse_url((string) $this->url, PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPES[$this->extension()] ?? 'video/mp4';
    }

    public function exists(): bool
    {
        return ! $this->isRemote() && Storage::disk($this->disk)->exists((string) $this->path);
    }

    public function size(): ?int
    {
        return $this->isRemote() ? null : Storage::disk($this->disk)->size((string) $this->path);
    }

    public function mimeType(): ?string
    {
        if ($this->isRemote()) {
            return null;
        }

        $mime = Storage::disk($this->disk)->mimeType((string) $this->path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * @return resource
     */
    public function readStream()
    {
        $stream = $this->isRemote() ? null : Storage::disk($this->disk)->readStream((string) $this->path);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException("Unable to open video [{$this->path}] on disk [{$this->disk}].");
        }

        return $stream;
    }

    public function description(): string
    {
        return $this->isRemote() ? (string) $this->url : "{$this->disk}:{$this->path}";
    }
}
