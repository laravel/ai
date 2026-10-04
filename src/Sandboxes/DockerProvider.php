<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\DockerDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

class DockerProvider extends Provider implements Checkpointable, Suspendable
{
    /**
     * The label every sandbox container, volume and checkpoint image carries.
     */
    public const LABEL = 'laravel-ai.sandbox';

    /**
     * The label holding the create options a restored container is started with again.
     */
    public const OPTIONS_LABEL = 'laravel-ai.options';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'workdir', 'cpus', 'memory', 'network'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        $id = strtolower((string) Str::ulid());

        $this->succeed($this->start($id, $options['image'] ?? $this->config['image'] ?? 'ubuntu:24.04', $options));

        return $this->handle($id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        if (! $this->valid($id)) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        $result = $this->docker(['inspect', '-f', '{{index .Config.Labels "'.static::OPTIONS_LABEL.'"}}', $this->container($id)]);

        if (! $result->successful()) {
            $this->ensureReachable($result);

            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id, json_decode(trim($result->output()) ?: '[]', true) ?: []);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id)) {
            return;
        }

        $result = $this->docker(['rm', '-f', '-v', $this->container($id)]);

        if (! $result->successful()) {
            $this->ensureReachable($result);
        }

        $volumes = $this->docker(['volume', 'ls', '-q', '--filter', 'label='.static::LABEL.'='.$id])->output();
        $images = $this->docker(['images', '-q', $this->container($id)])->output();

        $this->docker(['volume', 'rm', '-f', $this->container($id), ...array_filter(explode("\n", $volumes))]);

        if ($images = array_unique(array_filter(explode("\n", $images)))) {
            $this->docker(['rmi', '-f', ...$images]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->succeed(['stop', '-t', '0', $this->container($id)]);
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

        $this->succeed(['start', $this->container($id)]);
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $this->get($id);

        $checkpoint = strtolower((string) Str::ulid());

        // A commit leaves out mounted volumes, so the workspace is copied into a volume of its own...
        $this->succeed(['commit', $this->container($id), $this->image($id, $checkpoint)]);
        $this->succeed(['volume', 'create', '--label', static::LABEL.'='.$id, $this->volume($id, $checkpoint)]);
        $this->copyVolume($this->image($id, $checkpoint), $this->container($id), $this->volume($id, $checkpoint));

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $image = $this->succeed(['image', 'inspect', '-f', '{{index .Config.Labels "'.static::OPTIONS_LABEL.'"}}', $this->image($id, $checkpoint)]);
        $this->succeed(['volume', 'inspect', $this->volume($id, $checkpoint)]);

        $options = json_decode(trim($image->output()) ?: '[]', true) ?: [];

        $this->docker(['rm', '-f', $this->container($id)]);

        $this->copyVolume($this->image($id, $checkpoint), $this->volume($id, $checkpoint), $this->container($id));
        $this->succeed($this->start($id, $this->image($id, $checkpoint), $options));

        return $this->handle($id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $this->docker(['rmi', '-f', $this->image($id, $checkpoint)]);
        $this->docker(['volume', 'rm', '-f', $this->volume($id, $checkpoint)]);
    }

    /**
     * Get the container and workspace volume name of the sandbox with the given ID.
     */
    public function container(string $id): string
    {
        return 'ai-sandbox-'.$id;
    }

    /**
     * Create a handle for the sandbox with the given ID.
     *
     * @param  array<string, mixed>  $options
     */
    protected function handle(string $id, array $options): Sandbox
    {
        return new Sandbox(
            $this->name(),
            $id,
            new DockerDriver($this->name(), $id, $this->container($id), $this->config),
            $options['workdir'] ?? $this->workdir(),
        );
    }

    /**
     * Start the container of the sandbox with the given ID from the given image.
     *
     * @param  array<string, mixed>  $options
     */
    protected function start(string $id, string $image, array $options): ProcessResult
    {
        $name = $this->container($id);
        $workdir = $options['workdir'] ?? $this->workdir();
        $network = $options['network'] ?? $this->config['network'] ?? false;

        $env = collect([...$this->config['env'] ?? [], ...$options['env'] ?? []])
            ->flatMap(fn ($value, $key) => ['-e', "{$key}={$value}"])
            ->all();

        return $this->docker([
            'run', '-d',
            '--name', $name,
            '--label', static::LABEL.'='.$id,
            '--label', static::OPTIONS_LABEL.'='.json_encode(['image' => $image, ...$options], JSON_THROW_ON_ERROR),
            '--memory', (string) ($options['memory'] ?? $this->config['memory'] ?? '512m'),
            '--cpus', (string) ($options['cpus'] ?? $this->config['cpus'] ?? '1'),
            '--network', match (true) {
                $network === true => 'bridge',
                is_string($network) && $network !== '' => $network,
                default => 'none',
            },
            '--mount', "type=volume,source={$name},target={$workdir},volume-label=".static::LABEL."={$id}",
            '-w', $workdir,
            ...$env,
            $image,
            'sleep', 'infinity',
        ], timeout: 300);
    }

    /**
     * Replace the contents of one volume with the contents of another, using the given image's shell.
     */
    protected function copyVolume(string $image, string $from, string $to): void
    {
        $this->succeed([
            'run', '--rm', '--network', 'none', '--entrypoint', 'sh',
            '-v', "{$from}:/from:ro",
            '-v', "{$to}:/to",
            $image,
            '-c', 'find /to -mindepth 1 -delete && cp -a /from/. /to/',
        ]);
    }

    /**
     * Get the image a checkpoint of the sandbox with the given ID is committed to.
     */
    protected function image(string $id, string $checkpoint): string
    {
        return $this->container($id).':'.$checkpoint;
    }

    /**
     * Get the volume a checkpoint of the sandbox with the given ID copies its workspace to.
     */
    protected function volume(string $id, string $checkpoint): string
    {
        return $this->container($id).'-'.$checkpoint;
    }

    /**
     * Determine whether the given ID is one the provider could have created.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1;
    }

    /**
     * Fail when the Docker daemon itself could not be reached, rather than the container being missing.
     *
     * @throws SandboxException
     */
    protected function ensureReachable(ProcessResult $result): void
    {
        if (! Str::contains($result->errorOutput(), ['No such container', 'no such object', 'No such object'], ignoreCase: true)) {
            throw new SandboxException('Docker command failed: '.trim($result->errorOutput()), $this->name());
        }
    }

    /**
     * Run a docker command that must succeed.
     *
     * @param  array<int, string>|ProcessResult  $arguments
     *
     * @throws SandboxException
     */
    protected function succeed(array|ProcessResult $arguments): ProcessResult
    {
        $result = $arguments instanceof ProcessResult ? $arguments : $this->docker($arguments, timeout: 300);

        if (! $result->successful()) {
            throw new SandboxException('Docker command failed: '.trim($result->errorOutput()), $this->name());
        }

        return $result;
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
