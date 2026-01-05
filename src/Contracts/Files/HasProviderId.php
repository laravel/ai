<?php

namespace Laravel\Ai\Contracts\Files;

interface HasProviderId
{
    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string;
}
