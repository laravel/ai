<?php

namespace Laravel\Ai\Files;

use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;

class LocalDocument extends Document implements StorableFile
{
    use CanBeUploadedToProvider;

    public function __construct(public string $path, public ?string $mime = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return file_get_contents($this->path);
    }

    /**
     * Get the storable display name of the file.
     */
    public function storableName(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime ?? (new Filesystem)->mimeType($this->path);
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
