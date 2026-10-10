<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

class SandboxNotFound extends SandboxException
{
    /**
     * Create an exception for a sandbox that does not exist.
     */
    public static function for(string $provider, string $id, string $reason = ''): self
    {
        return new self(trim("Sandbox [{$id}] does not exist on [{$provider}]. {$reason}"), $provider, $id);
    }
}
