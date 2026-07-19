<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Enums\Lab;

interface HasHeaders
{
    /**
     * Get the HTTP headers to be sent with the provider request.
     *
     * @return array<string, string>
     */
    public function headers(Lab|string $provider): array;
}
