<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

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
        return $this->images[0];
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
        return sprintf(
            '<img src="data:%s;base64,%s" alt="%s" />',
            $this->images[0]->mime ?: 'image/png',
            $this->images[0]->image,
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
        return (string) $this->images[0];
    }
}
