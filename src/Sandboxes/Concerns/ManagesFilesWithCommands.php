<?php

namespace Laravel\Ai\Sandboxes\Concerns;

use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\ShellResult;

trait ManagesFilesWithCommands
{
    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $result = $this->command(['stat', '-c', '%F|%s|%Y', '--', $path]);

        if (! $result->successful()) {
            return null;
        }

        [$type, $size, $mtime] = explode('|', trim($result->stdout));

        return new FileStat(str_contains($type, 'regular'), $type === 'directory', (int) $size, (int) $mtime);
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        return array_values(array_filter(explode("\n", $this->commandOrFail(['ls', '-1A', '--', $path])->stdout)));
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->commandOrFail(['mkdir', ...($recursive ? ['-p'] : []), '--', $path]);
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->commandOrFail(['rm', ...($recursive ? ['-r'] : []), ...($force ? ['-f'] : []), '--', $path]);
    }

    /**
     * Run the given arguments as one escaped command in the sandbox.
     *
     * @param  array<int, string>  $arguments
     */
    protected function command(array $arguments): ShellResult
    {
        return $this->exec(implode(' ', array_map(escapeshellarg(...), $arguments)), '/', timeout: 60);
    }

    /**
     * Run the given arguments in the sandbox, failing when they do not succeed.
     *
     * @param  array<int, string>  $arguments
     *
     * @throws SandboxException
     */
    protected function commandOrFail(array $arguments): ShellResult
    {
        $result = $this->command($arguments);

        if (! $result->successful()) {
            throw new SandboxException(trim($result->stderr) ?: "Command failed with exit code {$result->exitCode}.", $this->provider, $this->id);
        }

        return $result;
    }
}
