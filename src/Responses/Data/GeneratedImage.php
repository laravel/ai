<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Ai\Concerns\Storable;

class GeneratedImage implements Arrayable, JsonSerializable
{
    use Storable;

    public ?string $mime = null;

    /**
     * @param  string  $image  The Base64 representation of the image.
     */
    public function __construct(
        public string $image,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get a default filename for the file.
     */
    protected function randomStorageName(): string
    {
        return once(fn () => Str::random(40).match ($this->mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.png',
        });
    }

    /**
     * Get the raw string content of the image.
     */
    public function content(): string
    {
        return base64_decode($this->image);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'image' => $this->image,
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
     * Get the raw string content of the image.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
