<?php

namespace Laravel\Ai\Files;

class LocalDocument extends Document implements StorableFile
{
    public function __construct(public string $path) {}

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
}
