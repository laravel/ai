<?php

namespace Laravel\Ai\Sandboxes\Drivers;

use Closure;
use Illuminate\Http\Client\PendingRequest;
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
use Throwable;

class BoatDriver implements SandboxDriver
{
    use ManagesFilesWithCommands, WrapsCommands;

    /**
     * The longest command Boat runs synchronously, in seconds.
     */
    protected const MAX_TIMEOUT = 600;

    public function __construct(
        protected string $provider,
        protected string $id,
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
     * Map a Boat sandbox state onto the SDK's states.
     */
    public static function mapState(?string $state): SandboxState
    {
        return match ($state) {
            'init', 'provisioning', 'provisioned', 'cloning' => SandboxState::Creating,
            'ready', 'idle', 'running' => SandboxState::Running,
            'archiving', 'archived' => SandboxState::Stopped,
            'cancelled', null => SandboxState::Terminated,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = static::client($this->config)->get("sandboxes/{$this->id}");

        if ($response->notFound()) {
            return SandboxState::Terminated;
        }

        return static::mapState($this->ensureSuccessful($response)->json('sandbox.state'));
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $timeout = min($timeout ?? $this->config['timeout'] ?? 120, static::MAX_TIMEOUT);

        // Boat only takes a working directory relative to its own, so the command changes into the absolute one itself...
        $payload = [
            'command' => $this->script($command, $cwd, $env),
            'timeoutSeconds' => $timeout,
        ];

        $request = static::client($this->config)->timeout($timeout + 30);

        if ($onOutput === null) {
            $result = $this->ensureSuccessful($request->post("sandboxes/{$this->id}/commands", $payload))->json();

            return new ShellResult($result['stdout'] ?? '', $result['stderr'] ?? '', $result['exitCode'] ?? 1, (bool) ($result['timedOut'] ?? false));
        }

        $response = $this->ensureSuccessful(
            $request->withOptions(['stream' => true])->post("sandboxes/{$this->id}/commands", [...$payload, 'stream' => true]),
        );

        return $this->stream($response, $onOutput);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = static::client($this->config)->get("sandboxes/{$this->id}/files", [
            'path' => $path,
            'encoding' => 'base64',
        ]);

        return base64_decode($this->ensureSuccessful($response)->json('content', ''));
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->ensureSuccessful(static::client($this->config)->put("sandboxes/{$this->id}/files", [
            'path' => $path,
            'content' => base64_encode($contents),
            'encoding' => 'base64',
        ]));
    }

    /**
     * Read Boat's newline-delimited command frames as they arrive.
     *
     * @param  Closure(string, string): void  $onOutput
     *
     * @throws SandboxException
     */
    protected function stream(Response $response, Closure $onOutput): ShellResult
    {
        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        $output = ['stdout' => '', 'stderr' => ''];
        $exit = null;

        $handle = function (string $line) use ($onOutput, &$output, &$exit): void {
            $frame = json_decode($line, true) ?? [];
            $type = $frame['type'] ?? null;

            if ($type === 'stdout' || $type === 'stderr') {
                $output[$type] .= $frame['data'];

                $onOutput($type, $frame['data']);
            } elseif ($type === 'exit') {
                $exit = $frame;
            } elseif ($type === 'error') {
                throw new SandboxException($frame['message'] ?? $frame['error'] ?? 'Boat command failed.', $this->provider, $this->id);
            }
        };

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(8192);

                while (($position = strpos($buffer, "\n")) !== false) {
                    $handle(substr($buffer, 0, $position));

                    $buffer = substr($buffer, $position + 1);
                }
            }

            if (trim($buffer) !== '') {
                $handle($buffer);
            }
        } catch (Throwable $exception) {
            // Closing the stream stops reading; Boat's own timeout bounds a command that keeps running remotely...
            $body->close();

            throw $exception;
        }

        if ($exit === null) {
            throw new SandboxException('Boat closed the command stream without an exit frame.', $this->provider, $this->id);
        }

        return new ShellResult($output['stdout'], $output['stderr'], $exit['exitCode'] ?? 1, (bool) ($exit['timedOut'] ?? false));
    }

    /**
     * Fail with the SDK's exceptions when Boat reports a missing or stopped sandbox, or any other error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) $response->json('message', $response->body());

        throw match (true) {
            $response->json('code') === 'sandbox_not_ready' => SandboxStateException::for(
                $this->provider, $this->id, static::mapState($response->json('state', $response->json('error.details.state'))), SandboxState::Running,
            ),
            $response->json('code') === 'boat_starting' => SandboxStateException::for(
                $this->provider, $this->id, SandboxState::Creating, SandboxState::Running,
            ),
            $response->notFound() && $response->json('code') !== 'file_not_found' => SandboxNotFound::for($this->provider, $this->id, $message),
            default => new SandboxException("Boat request failed with status {$response->status()}: {$message}", $this->provider, $this->id),
        };
    }
}
