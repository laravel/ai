<?php

namespace Laravel\Ai\Contracts;

interface HasMiddleware
{
    /**
     * Get the middleware wrapping each generation step of the agent.
     */
    public function middleware(): array;
}
