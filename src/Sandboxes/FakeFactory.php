<?php

namespace Laravel\Ai\Sandboxes;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\FakeDriver;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

class FakeFactory implements Checkpointable, ForgetsSandboxes, SandboxFactory, Suspendable
{
    /**
     * The directory every fake sandbox works in.
     */
    public const WORKSPACE = '/workspace';

    /** @var array<string, FakeDriver> */
    protected array $drivers = [];

    /** @var array<int, FakeDriver> */
    protected array $history = [];

    /** @var array<string, Closure(string): (ShellResult|string)> */
    protected array $commands = [];

    /** @var array<int, string> */
    protected array $forgotten = [];

    /** @var array<string, array{files: array<string, string>, directories: array<string, true>}> */
    protected array $checkpoints = [];

    /** @var array<int, string> */
    protected array $suspended = [];

    protected bool $suspendsAfterTurn = false;

    /**
     * @param  array<string, string>  $files
     */
    public function __construct(protected array $files = [])
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function create(string $id): Sandbox
    {
        $driver = $this->drivers[$id] ??= $this->history[] = $this->seed(
            new FakeDriver(fn (string $command) => $this->respond($command)),
        );

        return Sandbox::fromDriver($driver, static::WORKSPACE);
    }

    /**
     * {@inheritdoc}
     */
    public function tools(Sandbox $sandbox): ?array
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $id): void
    {
        unset($this->drivers[$id]);

        $this->forgotten[] = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $this->create($id);

        $driver = $this->drivers[$id];

        $this->checkpoints[$checkpoint = "{$id}:".count($this->checkpoints)] = [
            'files' => $driver->files,
            'directories' => $driver->directories,
        ];

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): void
    {
        $snapshot = $this->checkpoints[$checkpoint] ?? throw new RuntimeException("Checkpoint [{$checkpoint}] does not exist.");

        $this->create($id);

        $driver = $this->drivers[$id];

        $driver->files = $snapshot['files'];
        $driver->directories = $snapshot['directories'];
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->suspended[] = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function suspendsAfterTurn(): bool
    {
        return $this->suspendsAfterTurn;
    }

    /**
     * Suspend sandboxes when their turn ends, as a factory configured to do so would.
     */
    public function suspendAfterTurn(bool $suspend = true): self
    {
        $this->suspendsAfterTurn = $suspend;

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
     * Respond to commands matching the given pattern with the given output.
     *
     * @param  ShellResult|string|(Closure(string): (ShellResult|string))  $result
     */
    public function onExec(string $pattern, ShellResult|string|Closure $result): self
    {
        $this->commands[$pattern] = $result instanceof Closure ? $result : fn () => $result;

        return $this;
    }

    /**
     * Assert that a command matching the given pattern was run.
     */
    public function assertExecuted(string $pattern): self
    {
        PHPUnit::assertTrue(
            $this->executed()->contains(fn (string $command) => Str::is($pattern, $command)),
            "A command matching [{$pattern}] was not executed.",
        );

        return $this;
    }

    /**
     * Assert that no command was run.
     */
    public function assertNothingExecuted(): self
    {
        PHPUnit::assertEmpty($this->executed(), 'Commands were executed unexpectedly.');

        return $this;
    }

    /**
     * Assert that the given file was written.
     */
    public function assertWrote(string $path): self
    {
        PHPUnit::assertContains(
            $this->absolute($path),
            collect($this->history)->flatMap(fn (FakeDriver $driver) => $driver->written)->all(),
            "The file [{$path}] was not written.",
        );

        return $this;
    }

    /**
     * Assert that the given file exists, optionally passing its contents to the given callback.
     *
     * @param  (Closure(string): bool)|null  $callback
     */
    public function assertFile(string $path, ?Closure $callback = null): self
    {
        $contents = collect($this->history)
            ->map(fn (FakeDriver $driver) => $driver->files[$this->absolute($path)] ?? null)
            ->filter(fn (?string $contents) => $contents !== null);

        PHPUnit::assertNotEmpty($contents, "The file [{$path}] does not exist.");

        if ($callback !== null) {
            PHPUnit::assertTrue($contents->contains($callback), "The file [{$path}] does not match the expectation.");
        }

        return $this;
    }

    /**
     * Assert that the sandbox with the given ID was forgotten.
     */
    public function assertForgotten(string $id): self
    {
        PHPUnit::assertContains($id, $this->forgotten, "The sandbox [{$id}] was not forgotten.");

        return $this;
    }

    /**
     * Get the fake driver backing the sandbox with the given ID.
     */
    public function driver(string $id): ?FakeDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * Respond to the given command with the first matching scripted result.
     */
    protected function respond(string $command): ShellResult
    {
        foreach ($this->commands as $pattern => $respond) {
            if (Str::is($pattern, $command)) {
                $result = $respond($command);

                return $result instanceof ShellResult ? $result : ShellResult::ok($result);
            }
        }

        return ShellResult::ok();
    }

    /**
     * Seed the given driver with the workspace directory and the initial files.
     */
    protected function seed(FakeDriver $driver): FakeDriver
    {
        $driver->mkdir(static::WORKSPACE, recursive: true);

        foreach ($this->files as $path => $contents) {
            $path = $this->absolute($path);

            $driver->mkdir(dirname($path), recursive: true);
            $driver->files[$path] = $contents;
        }

        return $driver;
    }

    /**
     * Get every command run across the fake sandboxes.
     *
     * @return Collection<int, string>
     */
    protected function executed()
    {
        return collect($this->history)->flatMap(fn (FakeDriver $driver) => $driver->executed)->values();
    }

    /**
     * Resolve the given path against the fake workspace.
     */
    protected function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : static::WORKSPACE.'/'.ltrim($path, '/');
    }
}
