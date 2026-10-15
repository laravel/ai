<?php

namespace Laravel\Ai\Contracts\Sandbox;

use Illuminate\Contracts\Cache\Lock;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;
use Laravel\Ai\Sandboxes\Sandbox;

interface SandboxProvider
{
    /**
     * Get the configured name of the provider.
     */
    public function name(): string;

    /**
     * Create a new sandbox.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws UnsupportedOptionException
     */
    public function create(array $options = []): Sandbox;

    /**
     * Attach to the existing sandbox with the given ID.
     *
     * @throws SandboxNotFound
     */
    public function get(string $id): Sandbox;

    /**
     * Delete the sandbox with the given ID, succeeding when it no longer exists.
     */
    public function delete(string $id): void;

    /**
     * Get the lock callers share to coordinate work on the sandbox with the given ID.
     */
    public function lock(string $id, int $seconds): Lock;
}
