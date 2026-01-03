<?php

namespace Laravel\Ai\Files;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Files\StorableFile;
use RuntimeException;

class StoredDocument extends Document implements StorableFile
{
    public function __construct(public string $path, public ?string $disk = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return Storage::disk($this->disk)->get($this->path) ??
            throw new RuntimeException('File ['.$this->path.'] does not exist on disk ['.$this->disk.'].');
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return Storage::disk($this->disk)->mimeType($this->path);
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
