<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Ai\Concerns\Storable;

class GeneratedVideo implements Arrayable, JsonSerializable
{
    use Storable;

    public ?string $mime = null;

    public function __construct(
        public string $binary,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType ?? 'video/mp4';
    }

    /**
     * Get a default filename for the file.
     */
    protected function randomStorageName(): string
    {
        return once(fn () => Str::random(40).match ($this->mime) {
            'video/webm' => '.webm',
            default => '.mp4',
        });
    }

    /**
     * Get the raw binary content of the video.
     */
    public function content(): string
    {
        return $this->binary;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the raw string content of the video.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
