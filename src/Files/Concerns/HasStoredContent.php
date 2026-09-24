<?php

namespace Laravel\Ai\Files\Concerns;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

trait HasStoredContent
{
    /**
     * Get the raw representation of the file.
     *
     * @throws RuntimeException if the file does not exist on the configured disk.
     */
    public function content(): string
    {
        return Storage::disk($this->disk)->get($this->path) ??
            throw new RuntimeException('File ['.$this->path.'] does not exist on disk ['.$this->disk.'].');
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $this->mime ?? ($disk->mimeType($this->path) ?: null);
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
