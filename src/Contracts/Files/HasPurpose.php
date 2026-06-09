<?php

namespace Laravel\Ai\Contracts\Files;

interface HasPurpose
{

    /**
     * Get the purpose of the file.
     */
    public function purpose(): ?string;

    /**
     * Set the purpose of the file.
     */
    public function for(string $purpose): static;
}
