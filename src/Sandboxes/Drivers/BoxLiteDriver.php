<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\ManagesFilesWithCommands;
use Laravel\Ai\Sandboxes\Concerns\WrapsCommands;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;
use PharData;
use RecursiveIteratorIterator;

class BoxLiteDriver implements SandboxDriver
{
    use ManagesFilesWithCommands, WrapsCommands;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for a BoxLite server.
     */
    public static function client(array $config): PendingRequest
    {
        $prefix = isset($config['prefix']) ? '/'.trim($config['prefix'], '/') : '';

        return Http::baseUrl(rtrim($config['url'] ?? 'http://localhost:8100', '/').'/v1'.$prefix)
            ->withToken($config['key'] ?? '')
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * Map a BoxLite box status onto the SDK's states.
     */
    public static function mapState(?string $status): SandboxState
    {
        return match ($status) {
            'running' => SandboxState::Running,
            'configured', 'stopping', 'stopped', 'paused' => SandboxState::Stopped,
            null => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = static::client($this->config)->get("boxes/{$this->id}");

        return $response->notFound() ? SandboxState::Terminated : static::mapState($this->ensureSuccessful($response)->json('status'));
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        if ($onOutput !== null) {
            throw new SandboxException('BoxLite only streams output over a WebSocket, so this provider cannot stream it.', $this->provider, $this->id);
        }

        $timeout ??= $this->config['timeout'] ?? 120;

        // BoxLite returns output only over a WebSocket, so the command writes it to files that are read back afterwards...
        $capture = '/tmp/laravel-ai-'.Str::lower(Str::random(16));

        $execution = $this->ensureSuccessful(static::client($this->config)->post("boxes/{$this->id}/exec", [
            'command' => 'sh',
            'args' => ['-c', 'mkdir -p '.escapeshellarg($capture).' && ( '.$this->script($command, $cwd, $env).' ) > '.escapeshellarg("{$capture}/out").' 2> '.escapeshellarg("{$capture}/err")],
            'timeout_seconds' => $timeout,
        ]))->json('execution_id');

        $deadline = now()->addSeconds($timeout + 30);

        do {
            $info = $this->ensureSuccessful(static::client($this->config)->get("boxes/{$this->id}/executions/{$execution}"))->json();

            if ($info['status'] === 'running') {
                Sleep::for(250)->milliseconds();
            }
        } while ($info['status'] === 'running' && now()->isBefore($deadline));

        $output = $this->extract(static::client($this->config)->get("boxes/{$this->id}/files", ['path' => $capture]));

        static::client($this->config)->post("boxes/{$this->id}/exec", ['command' => 'rm', 'args' => ['-rf', $capture]]);

        return new ShellResult(
            $output['out'] ?? '',
            $output['err'] ?? '',
            (int) ($info['exit_code'] ?? ($info['status'] === 'timed_out' ? 124 : 1)),
            timedOut: $info['status'] === 'timed_out',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = static::client($this->config)->get("boxes/{$this->id}/files", ['path' => $path]);

        if ($response->notFound() || $response->badRequest()) {
            throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
        }

        return $this->extract($response)[basename($path)] ?? throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $tar = $this->temporary('tar');

        try {
            (new PharData($tar))->addFromString(basename($path), $contents);

            $this->ensureSuccessful(static::client($this->config)
                ->withBody(file_get_contents($tar), 'application/x-tar')
                ->put("boxes/{$this->id}/files?".http_build_query(['path' => dirname($path)])));
        } finally {
            @unlink($tar);
        }
    }

    /**
     * Unpack a downloaded tar archive into its files' contents, keyed by base name.
     *
     * @return array<string, string>
     */
    protected function extract(Response $response): array
    {
        $tar = $this->temporary('tar');

        try {
            file_put_contents($tar, $this->ensureSuccessful($response)->body());

            $files = [];

            foreach (new RecursiveIteratorIterator(new PharData($tar)) as $file) {
                $files[$file->getFilename()] = file_get_contents($file->getPathname());
            }

            return $files;
        } finally {
            @unlink($tar);
        }
    }

    /**
     * Get a unique temporary file name with the given extension, which PharData requires.
     */
    protected function temporary(string $extension): string
    {
        return sys_get_temp_dir().'/laravel-ai-'.Str::random(16).'.'.$extension;
    }

    /**
     * Fail when the BoxLite server returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) $response->json('error.message', $response->body());

        throw $response->notFound()
            ? SandboxNotFound::for($this->provider, $this->id, $message)
            : new SandboxException("BoxLite request failed with status {$response->status()}: {$message}", $this->provider, $this->id);
    }
}
