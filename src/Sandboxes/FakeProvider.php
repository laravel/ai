<?php

namespace Laravel\Ai\Sandboxes;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\FakeDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

class FakeProvider extends Provider implements Checkpointable, Suspendable
{
    /**
     * The directory every fake sandbox works in.
     */
    public const ROOT = '/workspace';

    /** @var array<string, FakeDriver> */
    protected array $drivers = [];

    /** @var array<string, array<string, mixed>> */
    protected array $created = [];

    /** @var array<int, string> */
    protected array $deleted = [];

    /** @var array<int, string> */
    protected array $suspended = [];

    /** @var array<string, array{id: string, files: array<string, string>, directories: array<string, true>}> */
    protected array $checkpoints = [];

    /** @var array<string, ShellResult|string|Throwable|Closure> */
    protected array $commands = [];

    /**
     * @param  array<string, string>  $files
     */
    public function __construct(protected array $files = [], array $config = [])
    {
        parent::__construct(['name' => 'fake', 'driver' => 'fake', ...$config]);
    }

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'workdir', 'cpus', 'memory', 'ttl', 'network', 'isolate', 'type'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $this->created[$id = (string) Str::ulid()] = $this->validate($options);

        $driver = $this->drivers[$id] = new FakeDriver($this->name(), $id, fn (string $command, Closure $output) => $this->respond($command, $output));

        $driver->directories[static::ROOT] = true;

        foreach ($this->files as $path => $contents) {
            $path = static::ROOT.'/'.ltrim($path, '/');

            for ($directory = dirname($path); $directory !== '/'; $directory = dirname($directory)) {
                $driver->directories[$directory] = true;
            }

            $driver->files[$path] = $contents;
        }

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        if (($this->drivers[$id] ?? null)?->state === SandboxState::Terminated || ! isset($this->drivers[$id])) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (isset($this->drivers[$id])) {
            $this->drivers[$id]->state = SandboxState::Terminated;
        }

        $this->deleted[] = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->drivers[$id]->state = SandboxState::Stopped;
        $this->suspended[] = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        $state = $this->get($id)->state();

        if ($state !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        $this->drivers[$id]->state = SandboxState::Running;
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $this->get($id);

        $this->checkpoints[$checkpoint = strtolower((string) Str::ulid())] = [
            'id' => $id,
            'files' => $this->drivers[$id]->files,
            'directories' => $this->drivers[$id]->directories,
        ];

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $snapshot = $this->checkpoints[$checkpoint] ?? null;

        if ($snapshot === null || $snapshot['id'] !== $id) {
            throw new SandboxException("Checkpoint [{$checkpoint}] of sandbox [{$id}] does not exist.", $this->name(), $id);
        }

        $this->get($id);

        $this->drivers[$id]->files = $snapshot['files'];
        $this->drivers[$id]->directories = $snapshot['directories'];

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        unset($this->checkpoints[$checkpoint]);
    }

    /**
     * Script the result of every command matching the given pattern.
     *
     * A closure receives the command and an output callback for streamed chunks, and returns the result.
     *
     * @param  ShellResult|string|Throwable|(Closure(string, Closure(string, string): void): (ShellResult|string))  $result
     */
    public function onExec(string $pattern, ShellResult|string|Throwable|Closure $result): self
    {
        $this->commands[$pattern] = $result;

        return $this;
    }

    /**
     * Assert that a sandbox was created, optionally with options passing the given callback.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    public function assertCreated(?Closure $callback = null): self
    {
        PHPUnit::assertTrue(
            collect($this->created)->contains(fn (array $options) => $callback === null || $callback($options)),
            'No matching sandbox was created.',
        );

        return $this;
    }

    /**
     * Assert that a command matching the given pattern ran, optionally in the sandbox with the given ID.
     */
    public function assertExecuted(string $pattern, ?string $id = null): self
    {
        PHPUnit::assertTrue(
            collect($this->executed($id))->contains(fn (string $command) => Str::is($pattern, $command)),
            "No command matching [{$pattern}] was executed.",
        );

        return $this;
    }

    /**
     * Assert that no command ran, optionally in the sandbox with the given ID.
     */
    public function assertNothingExecuted(?string $id = null): self
    {
        PHPUnit::assertEmpty($this->executed($id), 'Commands were executed: '.implode(', ', $this->executed($id)));

        return $this;
    }

    /**
     * Assert that the given file was written, optionally in the sandbox with the given ID.
     */
    public function assertWrote(string $path, ?string $id = null): self
    {
        PHPUnit::assertTrue(
            collect($this->drivers($id))->contains(fn (FakeDriver $driver) => in_array($this->absolute($path), $driver->written, true)),
            "The file [{$path}] was not written.",
        );

        return $this;
    }

    /**
     * Assert that the given file exists with contents passing the given callback, optionally in the sandbox with the given ID.
     *
     * @param  (Closure(string): bool)|null  $callback
     */
    public function assertFile(string $path, ?Closure $callback = null, ?string $id = null): self
    {
        PHPUnit::assertTrue(
            collect($this->drivers($id))->contains(fn (FakeDriver $driver) => isset($driver->files[$this->absolute($path)])
                && ($callback === null || $callback($driver->files[$this->absolute($path)]))),
            "The file [{$path}] does not exist or does not match.",
        );

        return $this;
    }

    /**
     * Assert that the sandbox with the given ID was deleted.
     */
    public function assertDeleted(string $id): self
    {
        PHPUnit::assertContains($id, $this->deleted, "The sandbox [{$id}] was not deleted.");

        return $this;
    }

    /**
     * Assert that the sandbox with the given ID was suspended.
     */
    public function assertSuspended(string $id): self
    {
        PHPUnit::assertContains($id, $this->suspended, "The sandbox [{$id}] was not suspended.");

        return $this;
    }

    /**
     * Create a handle for the fake sandbox with the given ID.
     */
    protected function handle(string $id): Sandbox
    {
        return new Sandbox($this->name(), $id, $this->drivers[$id], static::ROOT);
    }

    /**
     * Resolve the scripted result of the given command, failing loudly for one that was never scripted.
     *
     * @param  Closure(string, string): void  $output
     *
     * @throws Throwable
     */
    protected function respond(string $command, Closure $output): ShellResult
    {
        foreach ($this->commands as $pattern => $result) {
            if (! Str::is($pattern, $command)) {
                continue;
            }

            if ($result instanceof Throwable) {
                throw $result;
            }

            $result = $result instanceof Closure ? $result($command, $output) : $result;
            $result = is_string($result) ? ShellResult::ok($result) : $result;

            if (! $this->commands[$pattern] instanceof Closure) {
                foreach (['stdout' => $result->stdout, 'stderr' => $result->stderr] as $type => $chunk) {
                    if ($chunk !== '') {
                        $output($type, $chunk);
                    }
                }
            }

            return $result;
        }

        throw new SandboxException("The fake sandbox received the unscripted command [{$command}]. Script it with onExec().", $this->name());
    }

    /**
     * Get the commands run, optionally in the sandbox with the given ID.
     *
     * @return array<int, string>
     */
    protected function executed(?string $id): array
    {
        return collect($this->drivers($id))->flatMap(fn (FakeDriver $driver) => array_column($driver->executed, 'command'))->all();
    }

    /**
     * Get the drivers of every sandbox, or of the one with the given ID.
     *
     * @return array<string, FakeDriver>
     */
    protected function drivers(?string $id): array
    {
        return $id === null ? $this->drivers : array_intersect_key($this->drivers, [$id => true]);
    }

    /**
     * Resolve the given path against the fake root.
     */
    protected function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : static::ROOT.'/'.ltrim($path, '/');
    }
}
