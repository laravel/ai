<?php

namespace Laravel\Ai\Files\Concerns;

use Laravel\Ai\Files;
use Laravel\Ai\Responses\StoredFileResponse;

trait CanBeUploadedToProvider
{
    /**
     * Store the file on a given provider.
     */
    public function put(?string $mime = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return Files::put(
            $this,
            mime: $mime ?? $this->storableMimeType() ?? null,
            name: $name ?? $this->storableName() ?? null,
            provider: $provider
        );
    }
}
