<?php

namespace Laravel\Ai\Tools\Concerns;

trait HasCustomName
{
    public ?string $name = null;

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->name ?? class_basename($this);
    }

    /**
     * Set the name of the tool.
     */
    public function as(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
