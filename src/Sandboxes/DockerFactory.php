<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Sandboxes\Drivers\DockerDriver;
use RuntimeException;

class DockerFactory implements ForgetsSandboxes, SandboxFactory
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
            ! $state->successful() => $this->docker([
                'run', '-d',
                '--name', $name,
                '--label', static::LABEL.'='.$id,
                '--memory', $this->config['memory'] ?? '512m',
                '--cpus', (string) ($this->config['cpus'] ?? '1'),
                '--network', $this->config['network'] ?? 'none',
                '--mount', "type=volume,source={$name},target={$this->workdir()},volume-label=".static::LABEL."={$id}",
                '-w', $this->workdir(),
                $this->config['image'] ?? 'ubuntu:24.04',
                'sleep', 'infinity',
            ], timeout: 300),
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
    public function forget(string $id): void
    {
        $this->docker(['rm', '-f', $this->name($id)]);
        $this->docker(['volume', 'rm', '-f', $this->name($id)]);
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
