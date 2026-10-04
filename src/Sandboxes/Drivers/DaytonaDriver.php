<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\ManagesFilesWithCommands;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;

class DaytonaDriver implements SandboxDriver
{
    use ManagesFilesWithCommands;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected string $toolbox,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the Daytona control API.
     */
    public static function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim($config['url'] ?? 'https://app.daytona.io/api', '/'))
            ->withToken($config['key'] ?? '')
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * Map a Daytona sandbox state onto the SDK's states.
     */
    public static function mapState(?string $state): SandboxState
    {
        return match ($state) {
            'started', 'resizing', 'snapshotting', 'forking' => SandboxState::Running,
            'creating', 'restoring', 'starting', 'pending_build', 'building_snapshot', 'pulling_snapshot', 'resuming' => SandboxState::Creating,
            'stopped', 'stopping', 'archived', 'archiving', 'paused', 'pausing' => SandboxState::Stopped,
            'destroyed', 'destroying', null => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = static::client($this->config)->get("sandbox/{$this->id}");

        return $response->notFound() ? SandboxState::Terminated : static::mapState($this->ensureSuccessful($response)->json('state'));
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        if ($onOutput !== null) {
            throw new SandboxException('Daytona returns command output only when the command finishes, so it cannot stream it.', $this->provider, $this->id);
        }

        $timeout ??= $this->config['timeout'] ?? 120;

        $response = $this->toolbox()->timeout($timeout + 30)->post('process/execute', [
            'command' => $command,
            'cwd' => $cwd,
            'envs' => (object) $env,
            'timeout' => $timeout,
        ]);

        if ($response->status() === 408) {
            return new ShellResult('', '', 124, timedOut: true);
        }

        $result = $this->ensureSuccessful($response)->json();

        // Daytona combines stdout and stderr into a single result...
        return new ShellResult($result['result'] ?? '', '', (int) ($result['exitCode'] ?? 1));
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = $this->toolbox()->get('files/download', ['path' => $path]);

        if ($response->notFound() || $response->badRequest()) {
            throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
        }

        return $this->ensureSuccessful($response)->body();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->ensureSuccessful($this->toolbox()
            ->withBody($contents, 'application/octet-stream')
            ->post('files/upload-v2?'.http_build_query(['path' => $path])));
    }

    /**
     * Create an HTTP client for the sandbox's toolbox API.
     */
    protected function toolbox(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->toolbox, '/')."/{$this->id}")
            ->withToken($this->config['key'] ?? '')
            ->timeout(60);
    }

    /**
     * Fail when Daytona returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) $response->json('message', $response->body());

        throw $response->notFound()
            ? SandboxNotFound::for($this->provider, $this->id, $message)
            : new SandboxException("Daytona request failed with status {$response->status()}: {$message}", $this->provider, $this->id);
    }
}
