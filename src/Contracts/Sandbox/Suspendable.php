<?php

namespace Laravel\Ai\Contracts\Sandbox;

interface Suspendable
{
    /**
     * Stop the sandbox with the given ID, keeping its files until it is next created.
     */
    public function suspend(string $id): void;

    /**
     * Determine whether sandboxes are suspended when a turn ends.
     */
    public function suspendsAfterTurn(): bool;
}
