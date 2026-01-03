<?php

namespace Laravel\Ai\Files;

class LocalImage extends Image implements StorableFile
{
    public function __construct(public string $path, public ?string $mime = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return file_get_contents($this->path);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Set the image's MIME type.
     *
     * @return $this
     */
    public function withMime(string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }
}
