<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\DockerDriver;
use RuntimeException;

class DockerFactory implements Checkpointable, ForgetsSandboxes, SandboxFactory, Suspendable
{
    /**
     * The label every sandbox container and volume carries.
     */
    public const LABEL = 'laravel-ai.sandbox';

    public function __construct(protected array $config) {}

    /**
     * {@inheritdoc}
     */
    public function create(string $id): Sandbox
    {
        $name = $this->name($id);

        $state = $this->docker(['inspect', '-f', '{{.State.Running}}', $name]);

        $result = match (true) {
            ! $state->successful() => $this->start($id, $this->config['image'] ?? 'ubuntu:24.04'),
            trim($state->output()) === 'false' => $this->docker(['start', $name]),
            default => $state,
        };

        if (! $result->successful()) {
            throw new RuntimeException("Unable to start sandbox container [{$name}]: ".trim($result->errorOutput()));
        }

        return Sandbox::fromDriver(new DockerDriver($name, $this->config), $this->workdir());
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
    public function checkpoint(string $id): string
    {
        $this->create($id);

        $checkpoint = strtolower((string) Str::ulid());

        // A commit leaves out mounted volumes, so the workspace is copied into a volume of its own...
        $this->succeed(['commit', $this->name($id), $this->image($id, $checkpoint)]);
        $this->succeed(['volume', 'create', '--label', static::LABEL.'='.$id, $this->volume($id, $checkpoint)]);
        $this->copyVolume($this->name($id), $this->volume($id, $checkpoint));

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): void
    {
        $this->succeed(['image', 'inspect', $this->image($id, $checkpoint)]);

        $this->docker(['rm', '-f', $this->name($id)]);

        $this->copyVolume($this->volume($id, $checkpoint), $this->name($id));
        $this->succeed($this->start($id, $this->image($id, $checkpoint)));
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->docker(['stop', '-t', '0', $this->name($id)]);
    }

    /**
     * {@inheritdoc}
     */
    public function suspendsAfterTurn(): bool
    {
        return (bool) ($this->config['suspend_after_turn'] ?? false);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $id): void
    {
        $this->docker(['rm', '-f', $this->name($id)]);

        $volumes = $this->docker(['volume', 'ls', '-q', '--filter', 'label='.static::LABEL.'='.$id])->output();
        $images = $this->docker(['images', '-q', strtolower($this->name($id))])->output();

        $this->docker(['volume', 'rm', '-f', $this->name($id), ...array_filter(explode("\n", $volumes))]);
        $this->docker(['rmi', '-f', ...array_unique(array_filter(explode("\n", $images)))]);
    }

    /**
     * Start the container of the sandbox with the given ID from the given image.
     */
    protected function start(string $id, string $image): ProcessResult
    {
        $name = $this->name($id);

        return $this->docker([
            'run', '-d',
            '--name', $name,
            '--label', static::LABEL.'='.$id,
            '--memory', $this->config['memory'] ?? '512m',
            '--cpus', (string) ($this->config['cpus'] ?? '1'),
            '--network', $this->config['network'] ?? 'none',
            '--mount', "type=volume,source={$name},target={$this->workdir()},volume-label=".static::LABEL."={$id}",
            '-w', $this->workdir(),
            $image,
            'sleep', 'infinity',
        ], timeout: 300);
    }

    /**
     * Replace the contents of one volume with the contents of another.
     */
    protected function copyVolume(string $from, string $to): void
    {
        $this->succeed([
            'run', '--rm', '--network', 'none',
            '-v', "{$from}:/from:ro",
            '-v', "{$to}:/to",
            $this->config['image'] ?? 'ubuntu:24.04',
            'sh', '-c', 'find /to -mindepth 1 -delete && cp -a /from/. /to/',
        ]);
    }

    /**
     * Get the image a checkpoint of the sandbox with the given ID is committed to.
     */
    protected function image(string $id, string $checkpoint): string
    {
        return strtolower($this->name($id)).':'.$checkpoint;
    }

    /**
     * Get the volume a checkpoint of the sandbox with the given ID copies its workspace to.
     */
    protected function volume(string $id, string $checkpoint): string
    {
        return $this->name($id).'-'.$checkpoint;
    }

    /**
     * Run a docker command that must succeed.
     *
     * @param  array<int, string>|ProcessResult  $arguments
     *
     * @throws RuntimeException
     */
    protected function succeed(array|ProcessResult $arguments): ProcessResult
    {
        $result = $arguments instanceof ProcessResult ? $arguments : $this->docker($arguments, timeout: 300);

        if (! $result->successful()) {
            throw new RuntimeException('Sandbox checkpoint failed: '.trim($result->errorOutput()));
        }

        return $result;
    }

    /**
     * Get the container and volume name of the sandbox with the given ID.
     */
    public function name(string $id): string
    {
        return 'ai-sandbox-'.preg_replace('/[^a-zA-Z0-9_.-]/', '-', $id);
    }

    /**
     * Get the directory the sandbox works in inside the container.
     */
    protected function workdir(): string
    {
        return $this->config['workdir'] ?? '/workspace';
    }

    /**
     * Run the docker CLI with the given arguments.
     *
     * @param  array<int, string>  $arguments
     */
    protected function docker(array $arguments, int $timeout = 60): ProcessResult
    {
        return Process::timeout($timeout)->run([$this->config['binary'] ?? 'docker', ...$arguments]);
    }
}
