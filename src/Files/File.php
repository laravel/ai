<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\HasName;

abstract class File implements HasName
{
    public ?string $name = null;

    public ?string $mime = null;

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Set the displayable name of the file.
     */
    public function as(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the file's MIME type.
     */
    public function withMimeType(string $mimeType): static
    {
        $this->mime = $mimeType;

        return $this;
    }
}
