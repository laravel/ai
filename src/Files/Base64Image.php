<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\InlineSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasStoredBase64;
use Laravel\Ai\Files\Concerns\SerializesImageContent;

class Base64Image extends Image implements Arrayable, InlineSerializable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider, HasStoredBase64, SerializesImageContent;

    public function __construct(public string $base64, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-image',
            'name' => $this->name(),
            'base64' => $this->base64,
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

    public function __toString(): string
    {
        return $this->content();
    }
}
