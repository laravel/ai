<?php

namespace Laravel\Ai\Files;

use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Contracts\Files\StorableFile;

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
        return $this->mime ?? (new Filesystem)->mimeType($this->path);
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
