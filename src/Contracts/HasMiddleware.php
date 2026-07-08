<?php

namespace Laravel\Ai\Contracts;

interface HasMiddleware
{
    /**
     * Get the middleware that should run around each of the agent's generation steps.
     */
    public function middleware(): array;
}
