<?php

namespace Laravel\Ai\Sandboxes;

class ShellResult
{
    public function __construct(
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly int $exitCode = 0,
        public readonly bool $timedOut = false,
    ) {}

    /**
     * Create a result for a command that succeeded with the given output.
     */
    public static function ok(string $stdout = ''): self
    {
        return new self($stdout);
    }

    /**
     * Create a result for a command that failed with the given error output.
     */
    public static function failed(string $stderr = '', int $exitCode = 1): self
    {
        return new self('', $stderr, $exitCode);
    }

    /**
     * Determine whether the command exited successfully.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
