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
use Throwable;

class E2bDriver implements SandboxDriver
{
    use ManagesFilesWithCommands;

    /**
     * The port the in-sandbox daemon listens on.
     */
    protected const ENVD_PORT = 49983;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected ?string $accessToken,
        protected string $domain,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the E2B control plane.
     */
    public static function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim($config['url'] ?? 'https://api.e2b.app', '/'))
            ->withHeaders(['X-API-Key' => $config['key'] ?? ''])
            ->acceptJson()
            ->timeout(60);
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

        return match ($response->throw()->json('state')) {
            'running' => SandboxState::Running,
            'paused' => SandboxState::Stopped,
            default => SandboxState::Error,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $timeout ??= $this->config['timeout'] ?? 120;

        $message = json_encode([
            'process' => ['cmd' => '/bin/bash', 'args' => ['-l', '-c', $command], 'envs' => (object) $env, 'cwd' => $cwd],
            'stdin' => false,
        ], JSON_THROW_ON_ERROR);

        // Process.Start is a server-streaming Connect call, framed as a flag byte and a big-endian length before each message...
        $response = $this->envd(fn (PendingRequest $request) => $request
            ->withHeaders([
                'Content-Type' => 'application/connect+json',
                'Connect-Protocol-Version' => '1',
                'Connect-Timeout-Ms' => (string) ($timeout * 1000),
            ])
            ->timeout($timeout + 30)
            ->withOptions(['stream' => $onOutput !== null])
            ->withBody(pack('CN', 0, strlen($message)).$message, 'application/connect+json')
            ->post('process.Process/Start'));

        if ($response->notFound()) {
            throw SandboxNotFound::for($this->provider, $this->id, (string) $response->json('message', ''));
        }

        return $this->frames($this->ensureSuccessful($response), $onOutput ?? fn () => null);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = $this->envd(fn (PendingRequest $request) => $request->get('files', ['path' => $path]));

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
        $this->ensureSuccessful($this->envd(fn (PendingRequest $request) => $request
            ->attach('file', $contents, basename($path))
            ->post('files?'.http_build_query(['path' => $path]))));
    }

    /**
     * Read the Connect frames of a started process, passing output on as it arrives.
     *
     * @param  Closure(string, string): void  $onOutput
     *
     * @throws SandboxException
     */
    protected function frames(Response $response, Closure $onOutput): ShellResult
    {
        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        $output = ['stdout' => '', 'stderr' => ''];
        $end = null;
        $error = null;

        try {
            while (true) {
                while (strlen($buffer) >= 5 && strlen($buffer) >= 5 + ($length = unpack('N', substr($buffer, 1, 4))[1])) {
                    $flags = ord($buffer[0]);
                    $message = json_decode(substr($buffer, 5, $length), true) ?? [];
                    $buffer = substr($buffer, 5 + $length);

                    if ($flags & 0x02) {
                        $error = $message['error'] ?? null;

                        continue;
                    }

                    foreach (['stdout', 'stderr'] as $type) {
                        if (isset($message['event']['data'][$type])) {
                            $chunk = base64_decode($message['event']['data'][$type]);

                            $output[$type] .= $chunk;

                            $onOutput($type, $chunk);
                        }
                    }

                    $end = $message['event']['end'] ?? $end;
                }

                if ($body->eof()) {
                    break;
                }

                $buffer .= $body->read(8192);
            }
        } catch (Throwable $exception) {
            // Closing the stream ends the call, which cancels the Connect deadline's process...
            $body->close();

            throw $exception;
        }

        if (($error['code'] ?? null) === 'deadline_exceeded') {
            return new ShellResult($output['stdout'], $output['stderr'], 124, timedOut: true);
        }

        if ($end === null) {
            throw new SandboxException('E2B ended the command without an exit event: '.($error['message'] ?? 'unknown error'), $this->provider, $this->id);
        }

        return new ShellResult($output['stdout'], $output['stderr'], (int) ($end['exitCode'] ?? 0));
    }

    /**
     * Send a request to the sandbox's daemon, refreshing its access token once when it was rotated by a resume.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function envd(Closure $send): Response
    {
        $response = $send($this->daemon());

        if ($response->status() === 401) {
            $this->accessToken = static::client($this->config)->get("sandboxes/{$this->id}")->json('envdAccessToken');

            $response = $send($this->daemon());
        }

        return $response;
    }

    /**
     * Create an HTTP client for the sandbox's daemon.
     */
    protected function daemon(): PendingRequest
    {
        return Http::baseUrl('https://'.static::ENVD_PORT."-{$this->id}.{$this->domain}")
            ->withHeaders(array_filter(['X-Access-Token' => $this->accessToken]))
            ->timeout(60);
    }

    /**
     * Fail when the daemon returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->failed()) {
            throw new SandboxException("E2B request failed with status {$response->status()}: ".$response->json('message', $response->body()), $this->provider, $this->id);
        }

        return $response;
    }
}
