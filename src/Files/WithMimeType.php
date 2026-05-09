<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;

class WithMimeType implements StorableFile
{
    public function __construct(
        private StorableFile $file,
        private string $mimeType,
    ) {}

    public function content(): string
    {
        return $this->file->content();
    }

    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    public function name(): ?string
    {
        return $this->file->name();
    }

    public function as(?string $name): static
    {
        return new static($this->file->as($name), $this->mimeType);
    }

    public function __toString(): string
    {
        return (string) $this->file;
    }
}
