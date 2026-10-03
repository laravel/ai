<?php

namespace Laravel\Ai\Contracts\Sandbox;

interface ForgetsSandboxes
{
    /**
     * Destroy the sandbox with the given ID and everything in it.
     */
    public function forget(string $id): void;
}
