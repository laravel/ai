<?php

namespace Laravel\Ai\Contracts\Sandbox;

use Laravel\Ai\Sandboxes\Sandbox;

interface Checkpointable
{
    /**
     * Snapshot the sandbox with the given ID and return the checkpoint's name.
     */
    public function checkpoint(string $id): string;

    /**
     * Return the sandbox with the given ID to the given checkpoint, whose ID may differ from the original.
     */
    public function restore(string $id, string $checkpoint): Sandbox;

    /**
     * Delete the given checkpoint of the sandbox with the given ID.
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void;
}
