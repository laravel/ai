<?php

namespace Tests\Fixtures\Files;

use Laravel\Ai\Contracts\Files\StorableFile;

class CustomStorableFile implements StorableFile
{
    public function __construct(
        private string $name,
        private string $content = 'custom-content',
        private ?string $mimeType = 'text/plain',
    ) {}

    public function content(): string
    {
        return $this->content;
    }

    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function as(?string $name): static
    {
        $clone = clone $this;
        $clone->name = $name ?? $this->name;

        return $clone;
    }

    public function __toString(): string
    {
        return $this->content();
    }
}
