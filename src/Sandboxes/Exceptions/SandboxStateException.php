<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

use Laravel\Ai\Sandboxes\SandboxState;

class SandboxStateException extends SandboxException
{
    public function __construct(
        string $message,
        string $provider,
        string $sandboxId,
        public readonly SandboxState $current,
        public readonly SandboxState $required,
    ) {
        parent::__construct($message, $provider, $sandboxId);
    }

    /**
     * Create an exception for a sandbox that is not in the state the operation needs.
     */
    public static function for(string $provider, string $id, SandboxState $current, SandboxState $required): self
    {
        return new self(
            "Sandbox [{$id}] on [{$provider}] is {$current->value}, but must be {$required->value}.",
            $provider, $id, $current, $required,
        );
    }
}
