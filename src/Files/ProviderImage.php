<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider;

class ProviderImage extends Image implements HasProviderId
{
    use CanBeRetrievedOrDeletedFromProvider;

    public function __construct(public string $id) {}

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }
}
