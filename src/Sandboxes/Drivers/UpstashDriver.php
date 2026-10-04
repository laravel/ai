<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\WrapsCommands;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\FileStat;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;
use Throwable;

class UpstashDriver implements SandboxDriver
{
    use WrapsCommands;

    /**
     * The bytes held back from a streamed chunk so a trailer split across reads is never emitted as output.
     */
    protected const TRAILER_GUARD = 64;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the Upstash Box API.
     */
    public static function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim($config['url'] ?? 'https://us-east-1.box.upstash.com', '/'))
            ->withHeaders(['X-Box-Api-Key' => $config['key'] ?? ''])
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * Map an Upstash Box status onto the SDK's states.
     */
    public static function mapState(?string $status): SandboxState
    {
        return match ($status) {
            'creating' => SandboxState::Creating,
            'idle', 'running' => SandboxState::Running,
            'paused' => SandboxState::Stopped,
            'deleted', null => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = $this->http()->get("v2/box/{$this->id}/status");

        return $response->notFound() ? SandboxState::Terminated : static::mapState($this->ensureSuccessful($response)->json('status'));
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $timeout ??= $this->config['timeout'] ?? 120;

        $request = $this->http()->timeout($timeout + 30);
        $payload = ['command' => $this->argv($command, $cwd, $env, $timeout)];

        if ($onOutput === null) {
            $result = $this->ensureSuccessful($request->post("v2/box/{$this->id}/exec", $payload))->json();

            return new ShellResult($result['output'] ?? '', $result['error'] ?? '', $result['exit_code'] ?? 1, $this->timedOut($result['exit_code'] ?? null));
        }

        $response = $this->ensureSuccessful($request->withOptions(['stream' => true])->post("v2/box/{$this->id}/exec-stream", $payload));

        return $this->stream($response, $onOutput);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = $this->http()->get("v2/box/{$this->id}/files/read", ['path' => $path, 'encoding' => 'base64']);

        return base64_decode($this->ensureSuccessful($response, file: true)->json('content', ''));
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->ensureSuccessful($this->http()->post("v2/box/{$this->id}/files/write", [
            'path' => $path,
            'content' => base64_encode($contents),
            'encoding' => 'base64',
        ]), file: true);
    }

    /**
     * {@inheritdoc}
     */
    public function stat(string $path): ?FileStat
    {
        $response = $this->http()->get("v2/box/{$this->id}/files/stat", ['path' => $path]);

        if ($response->notFound()) {
            return null;
        }

        $stat = $this->ensureSuccessful($response)->json();

        return new FileStat($stat['type'] === 'file', $stat['type'] === 'directory', (int) ($stat['size'] ?? 0), (int) strtotime($stat['mod_time'] ?? 'now'));
    }

    /**
     * {@inheritdoc}
     */
    public function readdir(string $path): array
    {
        return collect($this->ensureSuccessful($this->http()->get("v2/box/{$this->id}/files/list", ['folder' => $path]), file: true)->json('files', []))
            ->pluck('name')
            ->all();
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->ensureSuccessful($this->http()->post("v2/box/{$this->id}/files/mkdir", ['path' => $path, 'parents' => $recursive]), file: true);
    }

    /**
     * {@inheritdoc}
     */
    public function rm(string $path, bool $recursive = false, bool $force = false): void
    {
        if ($force && $this->stat($path) === null) {
            return;
        }

        $this->ensureSuccessful($this->http()->post("v2/box/{$this->id}/files/remove", ['path' => $path, 'recursive' => $recursive]), file: true);
    }

    /**
     * Read the raw streamed output, which ends with an SSE-style exit or error event.
     *
     * @param  Closure(string, string): void  $onOutput
     *
     * @throws SandboxException
     */
    protected function stream(Response $response, Closure $onOutput): ShellResult
    {
        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        $emitted = 0;

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(8192);

                $end = min(strlen($buffer) - static::TRAILER_GUARD, $this->trailer($buffer) ?? PHP_INT_MAX);

                if ($end > $emitted) {
                    $onOutput('stdout', substr($buffer, $emitted, $end - $emitted));

                    $emitted = $end;
                }
            }

            $trailer = $this->trailer($buffer) ?? throw new SandboxException('Upstash closed the command stream without an exit event.', $this->provider, $this->id);

            if ($trailer > $emitted) {
                $onOutput('stdout', substr($buffer, $emitted, $trailer - $emitted));
            }
        } catch (Throwable $exception) {
            // Closing the stream stops reading; the timeout wrapper bounds a command that keeps running in the box...
            $body->close();

            throw $exception;
        }

        preg_match('/^event: (exit|error)\r?\ndata:\s*(.+)$/m', substr($buffer, $trailer), $matches);

        $data = json_decode($matches[2] ?? '{}', true) ?? [];

        if (($matches[1] ?? null) === 'error') {
            throw new SandboxException($data['error'] ?? 'Upstash command failed.', $this->provider, $this->id);
        }

        $exitCode = $data['exit_code'] ?? 0;

        return new ShellResult(substr($buffer, 0, $trailer), '', $exitCode, $this->timedOut($exitCode));
    }

    /**
     * Find where the exit or error trailer starts in the buffered output.
     */
    protected function trailer(string $buffer): ?int
    {
        $positions = array_filter([strpos($buffer, "event: exit\n"), strpos($buffer, "event: error\n")], fn ($position) => $position !== false);

        return $positions === [] ? null : min($positions);
    }

    /**
     * Fail with the SDK's exceptions when Upstash reports an error, where a file call's 404 means a missing path.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, bool $file = false): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) $response->json('error', $response->body());

        throw $response->notFound() && ! $file
            ? SandboxNotFound::for($this->provider, $this->id, $message)
            : new SandboxException("Upstash request failed with status {$response->status()}: {$message}", $this->provider, $this->id);
    }

    /**
     * Create an HTTP client for the Upstash Box API.
     */
    protected function http(): PendingRequest
    {
        return static::client($this->config);
    }
}
