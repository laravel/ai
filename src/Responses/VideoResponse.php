<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\GeneratedVideo;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class VideoResponse implements Countable, Htmlable
{
    public function __construct(
        public Collection $videos,
        public Usage $usage,
        public Meta $meta,
        public ?string $remoteId = null,
    ) {}

    /**
     * Get the first video in the response.
     */
    public function firstVideo(): GeneratedVideo
    {
        return $this->videos[0];
    }

    /**
     * Store the video on a filesystem disk.
     */
    public function store(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstVideo()->store($path, $disk, $options);
    }

    /**
     * Store the video on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstVideo()->storePublicly($path, $disk, $options);
    }

    /**
     * Store the video on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstVideo()->storePubliclyAs($path, $name, $disk, $options);
    }

    /**
     * Store the video on a filesystem disk.
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstVideo()->storeAs($path, $name, $disk, $options);
    }

    /**
     * Get a video tag for the video (expects a public URL; use after storing).
     */
    public function toHtml(string $src = '', string $alt = ''): string
    {
        return sprintf(
            '<video controls src="%s" playsinline>%s</video>',
            e($src),
            e($alt)
        );
    }

    /**
     * Get the number of videos that were generated.
     */
    public function count(): int
    {
        return count($this->videos);
    }

    /**
     * Get the raw string content of the first video.
     */
    public function __toString(): string
    {
        return (string) $this->videos[0];
    }
}
