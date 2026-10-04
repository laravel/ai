<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

class SandboxPathException extends SandboxException
{
    /**
     * Create an exception for a path that resolves outside the sandbox root.
     */
    public static function outside(string $path, string $root): self
    {
        return new self("Path [{$path}] is outside the sandbox root [{$root}].");
    }
}
