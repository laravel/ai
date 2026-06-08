<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use RuntimeException;

class ImageResponse implements Countable, Htmlable
{
    public function __construct(
        public Collection $images,
        public Usage $usage,
        public Meta $meta,
    ) {}

    /**
     * Get the first image in the response.
     */
    public function firstImage(): GeneratedImage
    {
        if ($this->images->isEmpty()) {
            throw new RuntimeException('The image response does not contain any images.');
        }

        return $this->images->first();
    }

    /**
     * Store the image on a filesystem disk.
     */
    public function store(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->store($path, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storePublicly($path, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storePubliclyAs($path, $name, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk.
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storeAs($path, $name, $disk, $options);
    }

    /**
     * Get an <img> tag for the image.
     */
    public function toHtml(string $alt = ''): string
    {
        $image = $this->firstImage();

        return sprintf(
            '<img src="data:%s;base64,%s" alt="%s" />',
            $image->mime(),
            $image->image,
            e($alt),
        );
    }

    /**
     * Get the number of images that were generated.
     */
    public function count(): int
    {
        return count($this->images);
    }

    /**
     * Get the raw string content of the image.
     */
    public function __toString(): string
    {
        return (string) $this->firstImage();
    }
}
