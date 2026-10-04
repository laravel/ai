<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxDied;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\ShellResult;
use RuntimeException;

class BoatDriver implements SandboxDriver
{
    /**
     * The longest command Boat runs synchronously, in seconds.
     */
    protected const MAX_TIMEOUT = 600;

    public function __construct(
        protected string $sandbox,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the Boat API.
     */
    public static function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim($config['url'] ?? 'https://boat.dev/api/v1', '/'))
            ->withToken($config['key'] ?? '')
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null): ShellResult
    {
        $timeout = min($timeout ?? $this->config['timeout'] ?? 120, static::MAX_TIMEOUT);

        $exports = collect([...$this->config['env'] ?? [], ...$env])
            ->map(fn ($value, $key) => 'export '.$key.'='.escapeshellarg((string) $value).'; ')
            ->implode('');

        // Boat only takes a working directory relative to its own, so the command changes into the absolute one itself...
        $response = static::client($this->config)
            ->timeout($timeout + 30)
            ->post("sandboxes/{$this->sandbox}/commands", [
                'command' => $exports.'cd -- '.escapeshellarg($cwd).' && '.$command,
                'timeoutSeconds' => $timeout,
            ]);

        if ($response->notFound()) {
            throw SandboxDied::for($this->sandbox, (string) $response->json('message', ''));
        }

        $this->ensureAlive($response);

        $result = $response->throw()->json();

        return new ShellResult(
            $result['stdout'] ?? '',
            $result['stderr'] ?? '',
            $result['exitCode'] ?? 1,
            timedOut: (bool) ($result['timedOut'] ?? false),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = static::client($this->config)->get("sandboxes/{$this->sandbox}/files", [
            'path' => $path,
            'encoding' => 'base64',
        ]);

        $this->ensureAlive($response);

        return base64_decode($response->throw()->json('content', ''));
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $response = static::client($this->config)->put("sandboxes/{$this->sandbox}/files", [
            'path' => $path,
            'content' => base64_encode($contents),
            'encoding' => 'base64',
        ]);

        $this->ensureAlive($response);

        $response->throw();
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $result = $this->run(['stat', '-c', '%F|%s|%Y', '--', $path]);

        if (! $result->successful()) {
            return null;
        }

        [$type, $size, $mtime] = explode('|', trim($result->stdout));

        return new FileStat(str_contains($type, 'regular'), $type === 'directory', (int) $size, (int) $mtime);
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        return array_values(array_filter(explode("\n", $this->succeed(['ls', '-1A', '--', $path])->stdout)));
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->succeed(['mkdir', ...($recursive ? ['-p'] : []), '--', $path]);
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        $this->succeed(['rm', ...($recursive ? ['-r'] : []), ...($force ? ['-f'] : []), '--', $path]);
    }

    /**
     * Run the given arguments as one escaped command in the sandbox.
     *
     * @param  array<int, string>  $arguments
     */
    protected function run(array $arguments): ShellResult
    {
        return $this->exec(implode(' ', array_map(escapeshellarg(...), $arguments)), '/', timeout: 60);
    }

    /**
     * Run the given arguments in the sandbox, failing when they do not succeed.
     *
     * @param  array<int, string>  $arguments
     *
     * @throws RuntimeException
     */
    protected function succeed(array $arguments): ShellResult
    {
        $result = $this->run($arguments);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: "Boat command failed with exit code {$result->exitCode}.");
        }

        return $result;
    }

    /**
     * Fail when Boat reports that the sandbox is no longer running.
     *
     * @throws SandboxDied
     */
    protected function ensureAlive(Response $response): void
    {
        if ($response->json('code') === 'sandbox_not_ready') {
            throw SandboxDied::for($this->sandbox, (string) $response->json('message', ''));
        }
    }
}
