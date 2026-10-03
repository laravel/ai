<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

use RuntimeException;

class SandboxPathException extends RuntimeException
{
    /**
     * Create an exception for a path that leaves the sandbox's working directory.
     */
    public static function outside(string $path, string $cwd): self
    {
        return new self("Path [{$path}] is outside the sandbox directory [{$cwd}].");
    }
}
