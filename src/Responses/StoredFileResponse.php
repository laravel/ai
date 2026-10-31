<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Responses\Concerns\HasRawResponse;

class StoredFileResponse implements HasProviderId
{
    use HasRawResponse;

    public function __construct(
        public readonly string $id,
    ) {}

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }
}
