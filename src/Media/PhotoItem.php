<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media;

use Illuminate\Support\Facades\Storage;

final readonly class PhotoItem
{
    public function __construct(
        public ?string $disk,
        public ?string $path,
        public ?string $url = null,
    ) {}

    public function isRemote(): bool
    {
        return $this->url !== null;
    }

    public function extension(): string
    {
        $path = $this->path ?? (string) parse_url((string) $this->url, PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
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

    public function contents(): ?string
    {
        return $this->isRemote() ? null : Storage::disk($this->disk)->get((string) $this->path);
    }

    public function description(): string
    {
        return $this->isRemote() ? (string) $this->url : "{$this->disk}:{$this->path}";
    }
}
