<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxPathException;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\ShellResult;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

class LocalDriver implements SandboxDriver
{
    /**
     * The environment variables forwarded from the host process.
     */
    protected const FORWARDED_ENV = ['PATH', 'HOME', 'LANG', 'TERM', 'TMPDIR'];

    public function __construct(
        protected array $config = [],
        protected Filesystem $files = new Filesystem,
        protected ?string $root = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null): ShellResult
    {
        try {
            $result = Process::path($cwd)
                ->env($this->environment($env))
                ->timeout($timeout ?? $this->config['timeout'] ?? 120)
                ->run(($this->config['isolate'] ?? true) ? $this->isolated($command, $cwd) : $command);
        } catch (ProcessTimedOutException $exception) {
            return new ShellResult(
                $exception->result->output(),
                $exception->result->errorOutput(),
                $exception->result->exitCode() ?? 124,
                timedOut: true,
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
            throw new RuntimeException("File [{$path}] does not exist.");
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
            throw new RuntimeException('Directory ['.dirname($path).'] does not exist.');
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
            throw new RuntimeException("Path [{$path}] does not exist.");
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
     * @throws RuntimeException
     */
    protected function isolated(string $command, string $cwd): array
    {
        $workspace = realpath($this->root ?? $cwd) ?: $cwd;
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
            default => throw new RuntimeException('Isolated local sandboxes are only supported on macOS and Linux.'),
        };
    }

    /**
     * Find the given isolation binary, failing loudly rather than running the command unisolated.
     *
     * @throws RuntimeException
     */
    protected function binary(string $name): string
    {
        return (new ExecutableFinder)->find($name) ?? throw new RuntimeException(
            "Isolated local sandboxes need [{$name}]. Install it or set AI_SANDBOX_ISOLATE=false to run commands unisolated.",
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
     * Reject a path that a symlink resolves outside the sandbox root.
     *
     * @throws SandboxPathException
     */
    protected function confine(string $path): void
    {
        if ($this->root === null) {
            return;
        }

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
