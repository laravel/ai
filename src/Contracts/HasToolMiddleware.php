<?php

namespace Laravel\Ai\Contracts;

interface HasToolMiddleware
{
    /**
     * Get the middleware wrapping each tool call the agent makes.
     */
    public function toolMiddleware(): array;
}
