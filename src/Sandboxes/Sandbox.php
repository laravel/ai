<?php

namespace Laravel\Ai\Sandboxes;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxBusy;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Throwable;

final class Sandbox
{
    /**
     * The number of seconds a turn may hold its sandbox before another turn can take it.
     */
    public const LOCK_SECONDS = 600;

    protected ?Sandbox $resolved = null;

    /**
     * @param  (Closure(): Sandbox)|null  $resolver
     */
    protected function __construct(
        protected ?SandboxDriver $driver = null,
        protected string $cwd = '/',
        protected ?Closure $resolver = null,
    ) {}

    /**
     * Wrap the given driver in a sandbox rooted at the given absolute directory.
     */
    public static function fromDriver(SandboxDriver $driver, string $cwd): self
    {
        return new self($driver, self::normalize($cwd));
    }

    /**
     * Create a sandbox that is only resolved once it is first used.
     *
     * @param  Closure(): Sandbox  $resolver
     */
    public static function defer(Closure $resolver): self
    {
        return new self(resolver: $resolver);
    }

    /**
     * Get the lock a turn holds while it works in the sandbox with the given ID.
     */
    public static function lock(string $id): Lock
    {
        return Cache::lock("ai:sandbox:{$id}", self::LOCK_SECONDS);
    }

    /**
     * Run the callback while holding the sandbox with the given ID, failing when a turn is working in it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws SandboxBusy
     */
    public static function exclusively(string $id, Closure $callback): mixed
    {
        $lock = self::lock($id);

        if (! $lock->get()) {
            throw SandboxBusy::for($id);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Get a sandbox working in the given directory, relative to this one.
     *
     * @throws SandboxPathException
     */
    public function withCwd(string $cwd): self
    {
        if ($this->resolver !== null) {
            return self::defer(fn () => $this->target()->withCwd($cwd));
        }

        return new self($this->driver, $this->resolvePath($cwd));
    }

    /**
     * Get the absolute directory the sandbox works in.
     */
    public function cwd(): string
    {
        return $this->target()->cwd;
    }

    /**
     * Get the driver the sandbox runs on.
     */
    public function driver(): SandboxDriver
    {
        return $this->target()->driver;
    }

    /**
     * Run a shell command in the sandbox's working directory.
     *
     * @param  array<string, string>  $env
     */
    public function exec(string $command, ?int $timeout = null, array $env = []): ShellResult
    {
        return $this->driver()->exec($command, $this->cwd(), $env, $timeout);
    }

    /**
     * Read the contents of the given file.
     *
     * @throws SandboxPathException
     */
    public function read(string $path): string
    {
        return $this->driver()->read($this->resolvePath($path));
    }

    /**
     * Write the contents to the given file, creating its parent directories.
     *
     * @throws SandboxPathException
     */
    public function write(string $path, string $contents): void
    {
        $path = $this->resolvePath($path);

        try {
            $this->driver()->write($path, $contents);
        } catch (Throwable) {
            $this->driver()->mkdir(dirname($path), recursive: true);

            $this->driver()->write($path, $contents);
        }
    }

    /**
     * Get the metadata of the given path, or null when it does not exist.
     *
     * @throws SandboxPathException
     */
    public function stat(string $path): ?FileStat
    {
        return $this->driver()->stat($this->resolvePath($path));
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
        return $this->driver()->readdir($this->resolvePath($path));
    }

    /**
     * Create the given directory.
     *
     * @throws SandboxPathException
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->driver()->mkdir($this->resolvePath($path), $recursive);
    }

    /**
     * Remove the given file or directory.
     *
     * @throws SandboxPathException
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->driver()->rm($this->resolvePath($path), $recursive, $force);
    }

    /**
     * Resolve the given path against the working directory, rejecting paths that leave it.
     *
     * @throws SandboxPathException
     */
    public function resolvePath(string $path): string
    {
        $cwd = $this->cwd();

        $resolved = self::normalize(str_starts_with($path, '/') ? $path : $cwd.'/'.$path);

        if ($resolved !== $cwd && ! str_starts_with($resolved, rtrim($cwd, '/').'/')) {
            throw SandboxPathException::outside($path, $cwd);
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

    /**
     * Get the sandbox the operations run against, resolving it on first use.
     */
    protected function target(): self
    {
        if ($this->resolver === null) {
            return $this;
        }

        return $this->resolved ??= ($this->resolver)();
    }
}
