<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\RunsProcesses;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;
use Symfony\Component\Process\ExecutableFinder;

class LocalDriver implements SandboxDriver
{
    use RunsProcesses;

    /**
     * The environment variables forwarded from the host process.
     */
    protected const FORWARDED_ENV = ['PATH', 'HOME', 'LANG', 'TERM', 'TMPDIR'];

    public function __construct(
        protected string $provider,
        protected string $id,
        protected string $root,
        protected array $config = [],
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        return is_dir($this->root) ? SandboxState::Running : SandboxState::Terminated;
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $this->ensureExists();

        $pending = Process::path($cwd)
            ->env($this->environment($env))
            ->timeout($timeout ?? $this->config['timeout'] ?? 120);

        try {
            $result = $this->runProcess(
                $pending,
                ($this->config['isolate'] ?? true) ? $this->isolated($command, $cwd) : ['sh', '-c', $command],
                $onOutput,
            );
        } catch (ProcessTimedOutException $exception) {
            return new ShellResult(
                $exception->result->output(),
                $exception->result->errorOutput(),
                $exception->result->exitCode() ?? 124,
                timedOut: true,
            );
        }

        // The isolation tool prefixes its own setup errors with its name, which the command's errors never carry...
        if (($this->config['isolate'] ?? true) && preg_match('/^(bwrap|sandbox-exec): /', $result->errorOutput())) {
            throw new SandboxException(
                'Isolated local sandboxes could not start: '.trim($result->errorOutput()).'. On Ubuntu 24.04 and later, allow bwrap to create user namespaces with an AppArmor profile, or set AI_SANDBOX_ISOLATE=false to run commands unisolated.',
                $this->provider,
                $this->id,
            );
        }

        return new ShellResult($result->output(), $result->errorOutput(), $result->exitCode() ?? 1);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $this->confine($path);

        if (! $this->files->isFile($path)) {
            throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
        }

        return $this->files->get($path);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->confine($path);

        if (! $this->files->isDirectory(dirname($path))) {
            throw new SandboxException('Directory ['.dirname($path).'] does not exist.', $this->provider, $this->id);
        }

        $this->files->put($path, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $this->confine($path);

        if (! $this->files->exists($path)) {
            return null;
        }

        return new FileStat(
            $this->files->isFile($path),
            $this->files->isDirectory($path),
            $this->files->isFile($path) ? $this->files->size($path) : 0,
            $this->files->lastModified($path),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        $this->confine($path);

        if (! $this->files->isDirectory($path)) {
            throw new SandboxException("Directory [{$path}] does not exist.", $this->provider, $this->id);
        }

        return array_values(array_diff(scandir($path) ?: [], ['.', '..']));
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->confine($path);

        $this->files->ensureDirectoryExists($path, recursive: $recursive);
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->confine($path);

        if (! $this->files->exists($path) && $force) {
            return;
        }

        if (! $this->files->exists($path)) {
            throw new SandboxException("Path [{$path}] does not exist.", $this->provider, $this->id);
        }

        match (true) {
            ! $this->files->isDirectory($path) => $this->files->delete($path),
            $recursive => $this->files->deleteDirectory($path),
            default => rmdir($path),
        };
    }

    /**
     * Wrap the command so the kernel only lets it write inside the workspace, and optionally cuts its network.
     *
     * @return array<int, string>
     *
     * @throws SandboxException
     */
    protected function isolated(string $command, string $cwd): array
    {
        $workspace = realpath($this->root) ?: $this->root;
        $network = $this->config['network'] ?? true;

        return match (PHP_OS_FAMILY) {
            'Darwin' => [$this->binary('sandbox-exec'), '-p', implode(' ', [
                '(version 1) (allow default) (deny file-write*)',
                '(allow file-write* (subpath '.$this->quote($workspace).') (subpath "/dev"))',
                $network ? '' : '(deny network*)',
            ]), 'sh', '-c', $command],
            'Linux' => [
                $this->binary('bwrap'),
                '--ro-bind', '/', '/',
                '--dev', '/dev',
                '--proc', '/proc',
                '--tmpfs', '/tmp',
                '--bind', $workspace, $workspace,
                ...($network ? [] : ['--unshare-net']),
                '--die-with-parent',
                '--chdir', $cwd,
                'sh', '-c', $command,
            ],
            default => throw new SandboxException('Isolated local sandboxes are only supported on macOS and Linux.', $this->provider, $this->id),
        };
    }

    /**
     * Find the given isolation binary, failing loudly rather than running the command unisolated.
     *
     * @throws SandboxException
     */
    protected function binary(string $name): string
    {
        return (new ExecutableFinder)->find($name) ?? throw new SandboxException(
            "Isolated local sandboxes need [{$name}]. Install it or set AI_SANDBOX_ISOLATE=false to run commands unisolated.",
            $this->provider,
            $this->id,
        );
    }

    /**
     * Quote the given path as a Seatbelt profile string.
     */
    protected function quote(string $path): string
    {
        return '"'.addcslashes($path, '"\\').'"';
    }

    /**
     * Fail when the workspace was deleted.
     *
     * @throws SandboxNotFound
     */
    protected function ensureExists(): void
    {
        if (! is_dir($this->root)) {
            throw SandboxNotFound::for($this->provider, $this->id);
        }
    }

    /**
     * Reject a path that a symlink resolves outside the sandbox root.
     *
     * @throws SandboxNotFound
     * @throws SandboxPathException
     */
    protected function confine(string $path): void
    {
        $this->ensureExists();

        $existing = $path;

        while (! file_exists($existing) && ! is_link($existing) && $existing !== dirname($existing)) {
            $existing = dirname($existing);
        }

        $real = realpath($existing);
        $root = realpath($this->root);

        if ($real === false || $root === false || ($real !== $root && ! str_starts_with($real, $root.'/'))) {
            throw SandboxPathException::outside($path, $this->root);
        }
    }

    /**
     * Build the command environment from the forwarded host variables, never the application's secrets.
     *
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    protected function environment(array $env): array
    {
        $host = getenv();

        $inherited = array_keys($host + $_ENV + array_filter($_SERVER, is_string(...)));

        return [
            ...array_fill_keys($inherited, false),
            ...Arr::only($host, static::FORWARDED_ENV),
            ...$this->config['env'] ?? [],
            ...$env,
        ];
    }
}
