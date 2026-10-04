<?php

namespace Laravel\Ai\Contracts\Sandbox;

use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

interface Suspendable
{
    /**
     * Stop the sandbox with the given ID, keeping its files.
     */
    public function suspend(string $id): void;

    /**
     * Start the stopped sandbox with the given ID again.
     *
     * @throws SandboxStateException
     */
    public function resume(string $id): void;
}
