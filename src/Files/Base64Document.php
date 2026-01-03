<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;

class Base64Document extends Document implements StorableFile
{
    public function __construct(public string $base64, public ?string $mime = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Set the document's MIME type.
     */
    public function withMime(string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
