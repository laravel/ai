<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Middleware\Middleware;

interface HasMiddleware
{
    /**
     * Get the agent's middleware, run around each generation step.
     *
     * @return Middleware[]
     */
    public function middleware(): array;
}
