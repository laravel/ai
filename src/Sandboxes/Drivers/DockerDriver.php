<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxDied;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\ShellResult;
use RuntimeException;

class DockerDriver implements SandboxDriver
{
    /**
     * The exit codes GNU and BusyBox timeout report when they stop a command.
     */
    protected const TIMED_OUT = [124, 143];

    public function __construct(
        protected string $container,
        protected array $config = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null): ShellResult
    {
        $timeout ??= $this->config['timeout'] ?? 120;

        $variables = collect([...$this->config['env'] ?? [], ...$env])
            ->flatMap(fn ($value, $key) => ['-e', "{$key}={$value}"])
            ->all();

        // The command is stopped inside the container; stopping only the docker CLI would leave it running there...
        $result = $this->docker(
            ['exec', '-w', $cwd, ...$variables, $this->container, 'timeout', (string) $timeout, 'sh', '-c', $command],
            timeout: $timeout + 10,
        );

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
     */
    protected function succeed(array $arguments, ?string $input = null): ProcessResult
    {
        $result = $this->docker($arguments, $input);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "Docker command failed with exit code {$result->exitCode()}.");
        }

        return $result;
    }

    /**
     * Run the docker CLI with the given arguments.
     *
     * @param  array<int, string>  $arguments
     *
     * @throws SandboxDied
     */
    protected function docker(array $arguments, ?string $input = null, int $timeout = 60): ProcessResult
    {
        $result = Process::timeout($timeout)
            ->input($input)
            ->run([$this->config['binary'] ?? 'docker', ...$arguments]);

        if (Str::contains($result->errorOutput(), ['is not running', 'No such container'])) {
            throw SandboxDied::for($this->container, trim($result->errorOutput()));
        }

        return $result;
    }
}
