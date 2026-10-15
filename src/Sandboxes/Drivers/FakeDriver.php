<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;

class FakeDriver implements SandboxDriver
{
    public SandboxState $state = SandboxState::Running;

    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    public array $directories = [];

    /** @var array<int, array{command: string, cwd: string, env: array<string, string>}> */
    public array $executed = [];

    /** @var array<int, string> */
    public array $written = [];

    /**
     * @param  Closure(string, Closure(string, string): void): ShellResult  $respond
     */
    public function __construct(
        protected string $provider,
        protected string $id,
        protected Closure $respond,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $this->ensureRunning();

        $this->executed[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];

        return ($this->respond)($command, $onOutput ?? fn () => null);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $this->ensureRunning();

        return $this->files[$path] ?? throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->ensureRunning();

        if (! isset($this->directories[dirname($path)])) {
            throw new SandboxException('Directory ['.dirname($path).'] does not exist.', $this->provider, $this->id);
        }

        $this->files[$path] = $contents;
        $this->written[] = $path;
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $this->ensureRunning();

        return match (true) {
            isset($this->files[$path]) => new FileStat(true, false, strlen($this->files[$path]), 0),
            isset($this->directories[$path]) => new FileStat(false, true, 0, 0),
            default => null,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        $this->ensureRunning();

        $prefix = rtrim($path, '/').'/';

        $entries = [];

        foreach ([...array_keys($this->files), ...array_keys($this->directories)] as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $entries[] = explode('/', substr($entry, strlen($prefix)))[0];
            }
        }

        $entries = array_values(array_unique($entries));

        sort($entries);

        return $entries;
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->ensureRunning();

        if (! $recursive && ! isset($this->directories[dirname($path)])) {
            throw new SandboxException('Directory ['.dirname($path).'] does not exist.', $this->provider, $this->id);
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
        $this->ensureRunning();

        if (! isset($this->files[$path]) && ! isset($this->directories[$path])) {
            if ($force) {
                return;
            }

            throw new SandboxException("Path [{$path}] does not exist.", $this->provider, $this->id);
        }

        unset($this->files[$path], $this->directories[$path]);

        foreach ([...array_keys($this->files), ...array_keys($this->directories)] as $entry) {
            if ($recursive && str_starts_with($entry, rtrim($path, '/').'/')) {
                unset($this->files[$entry], $this->directories[$entry]);
            }
        }
    }

    /**
     * Fail as a real sandbox would once it is deleted or stopped.
     *
     * @throws SandboxNotFound
     * @throws SandboxStateException
     */
    protected function ensureRunning(): void
    {
        match ($this->state) {
            SandboxState::Running => null,
            SandboxState::Terminated => throw SandboxNotFound::for($this->provider, $this->id),
            default => throw SandboxStateException::for($this->provider, $this->id, $this->state, SandboxState::Running),
        };
    }
}
