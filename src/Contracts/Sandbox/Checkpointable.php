<?php

namespace Laravel\Ai\Contracts\Sandbox;

interface Checkpointable
{
    /**
     * Snapshot the sandbox with the given ID and return the checkpoint's name.
     */
    public function checkpoint(string $id): string;

    /**
     * Return the sandbox with the given ID to the given checkpoint.
     */
    public function restore(string $id, string $checkpoint): void;
}
