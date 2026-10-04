<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\RunsProcesses;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;

class DockerDriver implements SandboxDriver
{
    use RunsProcesses;

    /**
     * The exit codes GNU and BusyBox timeout report when they stop a command.
     */
    protected const TIMED_OUT = [124, 143];

    public function __construct(
        protected string $provider,
        protected string $id,
        protected string $container,
        protected array $config = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $result = $this->process()->run([$this->binary(), 'inspect', '-f', '{{.State.Status}}', $this->container]);

        if (! $result->successful()) {
            return SandboxState::Terminated;
        }

        return match (trim($result->output())) {
            'created', 'restarting' => SandboxState::Creating,
            'running' => SandboxState::Running,
            'paused', 'exited' => SandboxState::Stopped,
            'removing' => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $timeout ??= $this->config['timeout'] ?? 120;

        $variables = collect($env)->flatMap(fn ($value, $key) => ['-e', "{$key}={$value}"])->all();

        // The command is stopped inside the container; stopping only the docker CLI would leave it running there...
        $result = $this->runProcess(
            $this->process($timeout + 10),
            [$this->binary(), 'exec', '-w', $cwd, ...$variables, $this->container, 'timeout', (string) $timeout, 'sh', '-c', $command],
            $onOutput,
        );

        $this->ensureRunning($result);

        return new ShellResult(
            $result->output(),
            $result->errorOutput(),
            $result->exitCode() ?? 1,
            timedOut: in_array($result->exitCode(), static::TIMED_OUT, true),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return $this->succeed(['exec', $this->container, 'cat', '--', $path])->output();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->succeed(['exec', '-i', $this->container, 'sh', '-c', 'cat > "$1"', 'sh', $path], input: $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $result = $this->docker(['exec', $this->container, 'stat', '-c', '%F|%s|%Y', '--', $path]);

        if (! $result->successful()) {
            return null;
        }

        [$type, $size, $mtime] = explode('|', trim($result->output()));

        return new FileStat(str_contains($type, 'regular'), $type === 'directory', (int) $size, (int) $mtime);
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        return array_values(array_filter(explode("\n", $this->succeed(['exec', $this->container, 'ls', '-1A', '--', $path])->output())));
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->succeed(['exec', $this->container, 'mkdir', ...($recursive ? ['-p'] : []), '--', $path]);
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->succeed(['exec', $this->container, 'rm', ...($recursive ? ['-r'] : []), ...($force ? ['-f'] : []), '--', $path]);
    }

    /**
     * Run a docker command that must succeed.
     *
     * @param  array<int, string>  $arguments
     *
     * @throws SandboxException
     */
    protected function succeed(array $arguments, ?string $input = null): ProcessResult
    {
        $result = $this->docker($arguments, $input);

        if (! $result->successful()) {
            throw new SandboxException(trim($result->errorOutput()) ?: "Docker command failed with exit code {$result->exitCode()}.", $this->provider, $this->id);
        }

        return $result;
    }

    /**
     * Run the docker CLI with the given arguments.
     *
     * @param  array<int, string>  $arguments
     */
    protected function docker(array $arguments, ?string $input = null, int $timeout = 60): ProcessResult
    {
        $result = $this->process($timeout)->input($input)->run([$this->binary(), ...$arguments]);

        $this->ensureRunning($result);

        return $result;
    }

    /**
     * Fail when the container is gone or stopped, which Docker reports only through its error output.
     *
     * @throws SandboxNotFound
     * @throws SandboxStateException
     */
    protected function ensureRunning(ProcessResult $result): void
    {
        if (Str::contains($result->errorOutput(), 'No such container')) {
            throw SandboxNotFound::for($this->provider, $this->id);
        }

        if (Str::contains($result->errorOutput(), 'is not running')) {
            throw SandboxStateException::for($this->provider, $this->id, SandboxState::Stopped, SandboxState::Running);
        }
    }

    /**
     * Create a pending process with the given timeout.
     */
    protected function process(int $timeout = 60): PendingProcess
    {
        return Process::timeout($timeout);
    }

    /**
     * Get the docker binary.
     */
    protected function binary(): string
    {
        return $this->config['binary'] ?? 'docker';
    }
}
