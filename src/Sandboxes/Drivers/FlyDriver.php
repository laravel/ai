<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Sandbox\SandboxDriver;
use Laravel\Ai\Sandboxes\Concerns\ManagesFilesWithCommands;
use Laravel\Ai\Sandboxes\Concerns\WrapsCommands;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;

class FlyDriver implements SandboxDriver
{
    use ManagesFilesWithCommands, WrapsCommands;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the app's machines.
     */
    public static function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim($config['url'] ?? 'https://api.machines.dev/v1', '/').'/apps/'.($config['app'] ?? ''))
            ->withToken($config['key'] ?? '')
            ->acceptJson()
            ->retry(3, 1000, fn ($exception) => $exception instanceof RequestException && $exception->response->status() === 429, throw: false)
            ->timeout(60);
    }

    /**
     * Map a Fly machine state onto the SDK's states.
     */
    public static function mapState(?string $state): SandboxState
    {
        return match ($state) {
            'started' => SandboxState::Running,
            'created', 'creating', 'starting', 'restarting', 'updating', 'replacing' => SandboxState::Creating,
            'stopped', 'stopping', 'suspended', 'suspending' => SandboxState::Stopped,
            'destroyed', 'destroying', 'replaced', 'migrated', null => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = static::client($this->config)->get("machines/{$this->id}");

        return $response->notFound() ? SandboxState::Terminated : static::mapState($this->ensureSuccessful($response)->json('state'));
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        if ($onOutput !== null) {
            throw new SandboxException('Fly returns command output only when the command finishes, so it cannot stream it.', $this->provider, $this->id);
        }

        return $this->run($this->argv($command, $cwd, $env, $timeout ??= $this->config['timeout'] ?? 120), $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $result = $this->run(['base64', '--', $path]);

        if (! $result->successful()) {
            throw new SandboxException(trim($result->stderr) ?: "File [{$path}] does not exist.", $this->provider, $this->id);
        }

        return base64_decode(str_replace(["\n", "\r"], '', $result->stdout));
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $result = $this->run(['sh', '-c', 'base64 -d > "$1"', 'sh', $path], stdin: base64_encode($contents));

        if (! $result->successful()) {
            throw new SandboxException(trim($result->stderr) ?: "Could not write [{$path}].", $this->provider, $this->id);
        }
    }

    /**
     * Run the given arguments on the machine.
     *
     * @param  array<int, string>  $command
     */
    protected function run(array $command, int $timeout = 60, ?string $stdin = null): ShellResult
    {
        $response = static::client($this->config)->timeout($timeout + 30)->post("machines/{$this->id}/exec", array_filter([
            'command' => $command,
            'timeout' => $timeout,
            'stdin' => $stdin,
        ], fn ($value) => $value !== null));

        $result = $this->ensureSuccessful($response)->json();

        $exitCode = (int) ($result['exit_code'] ?? 0);

        return new ShellResult($result['stdout'] ?? '', $result['stderr'] ?? '', $exitCode, $this->timedOut($exitCode));
    }

    /**
     * {@inheritdoc}
     */
    protected function command(array $arguments): ShellResult
    {
        return $this->run($arguments);
    }

    /**
     * Fail when the Machines API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) $response->json('error', $response->body());

        throw match (true) {
            $response->notFound() => SandboxNotFound::for($this->provider, $this->id, $message),
            $response->status() === 412 || str_contains($message, 'not started') => SandboxStateException::for($this->provider, $this->id, SandboxState::Stopped, SandboxState::Running),
            default => new SandboxException("Fly request failed with status {$response->status()}: {$message}", $this->provider, $this->id),
        };
    }
}
