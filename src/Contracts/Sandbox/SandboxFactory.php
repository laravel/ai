<?php

namespace Laravel\Ai\Contracts\Sandbox;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Sandboxes\Sandbox;

interface SandboxFactory
{
    /**
     * Get the sandbox for the given ID, creating it when it does not exist yet.
     */
    public function create(string $id): Sandbox;

    /**
     * Get the tools agents use to work in the sandbox, or null for the default set.
     *
     * @return array<int, Tool>|null
     */
    public function tools(Sandbox $sandbox): ?array;
}
