<?php

namespace Laravel\Ai\Contracts\Sandbox;

use Closure;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;

interface SandboxDriver
{
    /**
     * Get the current state of the sandbox.
     */
    public function state(): SandboxState;

    /**
     * Run a shell command in the given directory.
     *
     * @param  array<string, string>  $env
     * @param  (Closure(string, string): void)|null  $onOutput
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult;

    /**
     * Read the contents of the file at the given absolute path.
     */
    public function read(string $path): string;

    /**
     * Write the contents to the file at the given absolute path.
     */
    public function write(string $path, string $contents): void;

    /**
     * Get the metadata of the given absolute path, or null when it does not exist.
     */
    public function stat(string $path): ?FileStat;

    /**
     * List the entry names inside the given absolute directory path.
     *
     * @return array<int, string>
     */
    public function readdir(string $path): array;

    /**
     * Create the directory at the given absolute path.
     */
    public function mkdir(string $path, bool $recursive = false): void;

    /**
     * Remove the file or directory at the given absolute path.
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void;
}
