<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

use RuntimeException;

class SandboxBusy extends RuntimeException
{
    /**
     * Create an exception for a sandbox another turn is still working in.
     */
    public static function for(string $id): self
    {
        return new self("Sandbox [{$id}] is in use by another turn.");
    }
}
