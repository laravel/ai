<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

use RuntimeException;

class SandboxDied extends RuntimeException
{
    /**
     * Create an exception for a sandbox that is no longer running.
     */
    public static function for(string $name, string $reason = ''): self
    {
        return new self(trim("Sandbox [{$name}] is no longer running. {$reason}"));
    }
}
