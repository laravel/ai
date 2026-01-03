<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Str;
use Laravel\Ai\Concerns\Storable;
use Laravel\Ai\Responses\Data\Meta;

class AudioResponse
{
    use Storable;

    /**
     * @param  string  $audio  The Base64 representation of the audio.
     */
    public function __construct(
        public string $audio,
        public Meta $meta,
        public ?string $mime = null,
    ) {}

    /**
     * Get a default filename for the file.
     */
    protected function randomStorageName(): string
    {
        return once(fn () => Str::random(40).match ($this->mime) {
            default => '.mp3',
        });
    }

    /**
     * Get the raw representation of the audio.
     */
    public function content(): string
    {
        return base64_decode($this->audio);
    }

    /**
     * Get the MIME type for the audio.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the raw string content of the audio.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
