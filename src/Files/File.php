<?php

namespace Laravel\Ai\Files;

abstract class File
{
    public ?string $name = null;

    /**
     * Set the file's name.
     */
    public function as(?string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
