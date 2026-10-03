<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\ShellResult;
use RuntimeException;

class FakeDriver implements SandboxDriver
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    public array $directories = [];

    /** @var array<int, string> */
    public array $executed = [];

    /** @var array<int, string> */
    public array $written = [];

    /**
     * @param  Closure(string): ShellResult  $respond
     */
    public function __construct(protected Closure $respond)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null): ShellResult
    {
        $this->executed[] = $command;

        return ($this->respond)($command);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return $this->files[$path] ?? throw new RuntimeException("File [{$path}] does not exist.");
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        if (! isset($this->directories[dirname($path)])) {
            throw new RuntimeException('Directory ['.dirname($path).'] does not exist.');
        }

        $this->files[$path] = $contents;
        $this->written[] = $path;
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        return match (true) {
            isset($this->files[$path]) => new FileStat(true, false, strlen($this->files[$path])),
            isset($this->directories[$path]) => new FileStat(false, true),
            default => null,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        $prefix = rtrim($path, '/').'/';

        return collect([...array_keys($this->files), ...array_keys($this->directories)])
            ->filter(fn (string $entry) => str_starts_with($entry, $prefix) && ! str_contains(substr($entry, strlen($prefix)), '/'))
            ->map(fn (string $entry) => substr($entry, strlen($prefix)))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        if (! $recursive && dirname($path) !== '/' && ! isset($this->directories[dirname($path)])) {
            throw new RuntimeException('Directory ['.dirname($path).'] does not exist.');
        }

        for ($directory = $path; $directory !== '/'; $directory = dirname($directory)) {
            $this->directories[$directory] = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        if ($this->stat($path) === null && ! $force) {
            throw new RuntimeException("Path [{$path}] does not exist.");
        }

        $prefix = rtrim($path, '/').'/';

        unset($this->files[$path], $this->directories[$path]);

        if ($recursive) {
            $this->files = array_filter($this->files, fn ($key) => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
            $this->directories = array_filter($this->directories, fn ($key) => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        }
    }
}
