<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Tools\Request;

interface Interactive
{
    /**
     * Get the payload the client renders, or null to run handle() without a round-trip.
     *
     * @return array<string, mixed>|null
     */
    public function ask(Request $request): ?array;
}
