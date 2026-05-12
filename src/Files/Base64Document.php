<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;

class Base64Document extends Document implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;

    public function __construct(public string $base64, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Create a new instance from an uploaded file.
     */
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): self
    {
        return new self(
            base64_encode($file->getContent()),
            mimeType: $mimeType ?? $file->getClientMimeType(),
        );
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
            'type' => 'base64-document',
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
