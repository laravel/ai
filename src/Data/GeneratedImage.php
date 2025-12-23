<?php

namespace Laravel\Ai\Data;

use Illuminate\Support\Str;
use Laravel\Ai\Concerns\Storable;

class GeneratedImage
{
    use Storable;

    /**
     * @param  string  $image  The Base64 representation of the image.
     */
    public function __construct(
        public string $image,
        public ?string $mime = null,
    ) {}

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
     * Get the raw representation of the image.
     */
    public function raw(): string
    {
        return base64_decode($this->image);
    }

    /**
     * Get the raw string content of the image.
     */
    public function __toString(): string
    {
        return $this->raw();
    }
}
