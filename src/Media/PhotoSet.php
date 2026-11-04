<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Media;

use InvalidArgumentException;

/**
 * Immutable input object for a photo slideshow (MEDIA_UPLOAD).
 */
final class PhotoSet
{
    /**
     * @param  list<PhotoItem>  $photos
     */
    private function __construct(
        private readonly array $photos,
        private ?string $title = null,
        private ?string $description = null,
        private int $coverIndex = 0,
    ) {}

    /**
     * @param  list<string>  $paths
     */
    public static function fromDisk(string $disk, array $paths): self
    {
        return new self(array_map(
            static fn (string $path): PhotoItem => new PhotoItem($disk, $path),
            $paths,
        ));
    }

    /**
     * Photos that are already reachable via public URLs on a verified domain.
     *
     * @param  list<string>  $urls
     */
    public static function fromUrls(array $urls): self
    {
        return new self(array_map(static function (string $url): PhotoItem {
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("[{$url}] is not a valid URL.");
            }

            return new PhotoItem(null, null, $url);
        }, $urls));
    }

    /**
     * @param  list<PhotoItem>  $photos
     */
    public static function fromItems(array $photos): self
    {
        return new self($photos);
    }

    public function title(?string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    public function description(?string $description): self
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    public function cover(int $index): self
    {
        $clone = clone $this;
        $clone->coverIndex = $index;

        return $clone;
    }

    /**
     * @return list<PhotoItem>
     */
    public function photos(): array
    {
        return $this->photos;
    }

    public function count(): int
    {
        return count($this->photos);
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCoverIndex(): int
    {
        return $this->coverIndex;
    }
}
