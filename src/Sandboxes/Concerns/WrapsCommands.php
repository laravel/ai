<?php

namespace Laravel\Ai\Sandboxes\Concerns;

trait WrapsCommands
{
    /**
     * The exit codes GNU and BusyBox timeout report when they stop a command.
     */
    protected static array $timedOutExitCodes = [124, 143];

    /**
     * Wrap the command so it exports the environment and changes into the directory itself, for backends that take neither.
     *
     * @param  array<string, string>  $env
     */
    protected function script(string $command, string $cwd, array $env = []): string
    {
        $exports = collect($env)
            ->map(fn ($value, $key) => 'export '.$key.'='.escapeshellarg((string) $value).'; ')
            ->implode('');

        return $exports.'cd -- '.escapeshellarg($cwd).' && '.$command;
    }

    /**
     * Get the argument list that runs the script under coreutils timeout, which stops it inside the sandbox.
     *
     * @param  array<string, string>  $env
     * @return array<int, string>
     */
    protected function argv(string $command, string $cwd, array $env, int $timeout): array
    {
        return ['timeout', (string) $timeout, 'sh', '-c', $this->script($command, $cwd, $env)];
    }

    /**
     * Determine whether the exit code means coreutils timeout stopped the command.
     */
    protected function timedOut(?int $exitCode): bool
    {
        return in_array($exitCode, static::$timedOutExitCodes, true);
    }
}
