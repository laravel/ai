<?php

namespace Laravel\Ai\Sandboxes;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;

final class Sandbox
{
    protected string $root;

    protected string $cwd;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected SandboxDriver $driver,
        string $root,
        ?string $cwd = null,
    ) {
        $this->root = $this->cwd = self::normalize($root);

        if ($cwd !== null) {
            $this->cwd = $this->resolvePath($cwd);
        }
    }

    /**
     * Get the configured name of the provider the sandbox belongs to.
     */
    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * Get the provider's ID of the sandbox.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the current state of the sandbox.
     */
    public function state(): SandboxState
    {
        return $this->driver->state();
    }

    /**
     * Get the absolute directory paths are confined to.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Get the absolute directory commands run in and relative paths resolve against.
     */
    public function cwd(): string
    {
        return $this->cwd;
    }

    /**
     * Get a handle working in the given directory, which stays confined to the same root.
     *
     * @throws SandboxPathException
     */
    public function withCwd(string $cwd): self
    {
        return new self($this->provider, $this->id, $this->driver, $this->root, $this->resolvePath($cwd));
    }

    /**
     * Get the driver the sandbox runs on.
     */
    public function driver(): SandboxDriver
    {
        return $this->driver;
    }

    /**
     * Run a shell command in the working directory.
     *
     * @param  array<string, string>  $env
     * @param  (Closure(string, string): void)|null  $onOutput
     *
     * @throws InvalidArgumentException
     */
    public function exec(string $command, ?int $timeout = null, array $env = [], ?Closure $onOutput = null): ShellResult
    {
        foreach (array_keys($env) as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new InvalidArgumentException("Invalid environment variable name [{$name}].");
            }
        }

        return $this->driver->exec($command, $this->cwd, $env, $timeout, $onOutput);
    }

    /**
     * Read the contents of the given file.
     *
     * @throws SandboxPathException
     */
    public function read(string $path): string
    {
        return $this->driver->read($this->resolvePath($path));
    }

    /**
     * Write the contents to the given file, creating its parent directories.
     *
     * @throws SandboxPathException
     */
    public function write(string $path, string $contents): void
    {
        $path = $this->resolvePath($path);

        if ($this->driver->stat(dirname($path)) === null) {
            $this->driver->mkdir(dirname($path), recursive: true);
        }

        $this->driver->write($path, $contents);
    }

    /**
     * Get the metadata of the given path, or null when it does not exist.
     *
     * @throws SandboxPathException
     */
    public function stat(string $path): ?FileStat
    {
        return $this->driver->stat($this->resolvePath($path));
    }

    /**
     * Determine whether the given path exists.
     *
     * @throws SandboxPathException
     */
    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    /**
     * List the entry names inside the given directory.
     *
     * @return array<int, string>
     *
     * @throws SandboxPathException
     */
    public function readdir(string $path = '.'): array
    {
        return $this->driver->readdir($this->resolvePath($path));
    }

    /**
     * Create the given directory.
     *
     * @throws SandboxPathException
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->driver->mkdir($this->resolvePath($path), $recursive);
    }

    /**
     * Remove the given file or directory.
     *
     * @throws SandboxPathException
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->driver->rm($this->resolvePath($path), $recursive, $force);
    }

    /**
     * Resolve the given path against the working directory, rejecting paths that leave the root.
     *
     * @throws SandboxPathException
     */
    public function resolvePath(string $path): string
    {
        $resolved = self::normalize(str_starts_with($path, '/') ? $path : $this->cwd.'/'.$path);

        if ($resolved !== $this->root && ! str_starts_with($resolved, rtrim($this->root, '/').'/')) {
            throw SandboxPathException::outside($path, $this->root);
        }

        return $resolved;
    }

    /**
     * Collapse the dot segments and duplicate slashes of the given absolute path.
     */
    protected static function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return '/'.implode('/', $segments);
    }
}
