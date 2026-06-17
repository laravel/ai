<?php

namespace Laravel\Ai\Contracts\Files;

use Laravel\Ai\Enums\Lab;
use Stringable;

interface StorableFile extends HasContent, HasMimeType, HasName, Stringable
{
    /**
     * Get provider-specific options for the file upload.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array;
}
