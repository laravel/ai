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
use Laravel\Ai\Sandboxes\SandboxState;
use Laravel\Ai\Sandboxes\ShellResult;
use Throwable;

class CloudflareDriver implements SandboxDriver
{
    use ManagesFilesWithCommands, WrapsCommands;

    public function __construct(
        protected string $provider,
        protected string $id,
        protected array $config = [],
    ) {}

    /**
     * Create an HTTP client for the deployed Sandbox SDK bridge Worker.
     *
     * @throws SandboxException
     */
    public static function client(array $config): PendingRequest
    {
        $url = $config['url'] ?? throw new SandboxException('Set the URL of your Cloudflare sandbox bridge Worker in the [url] option.');

        return Http::baseUrl(rtrim($url, '/').'/v1')
            ->withToken($config['key'] ?? '')
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * {@inheritdoc}
     */
    public function state(): SandboxState
    {
        $response = static::client($this->config)->get("sandbox/{$this->id}/running");

        return $this->ensureSuccessful($response)->json('running') ? SandboxState::Running : SandboxState::Stopped;
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $command, string $cwd, array $env = [], ?int $timeout = null, ?Closure $onOutput = null): ShellResult
    {
        $timeout ??= $this->config['timeout'] ?? 120;

        $response = $this->ensureSuccessful(static::client($this->config)
            ->timeout($timeout + 30)
            ->withOptions(['stream' => $onOutput !== null])
            ->post("sandbox/{$this->id}/exec", [
                'argv' => $this->argv($command, $cwd, $env, $timeout),
                'timeout_ms' => ($timeout + 5) * 1000,
            ]));

        return $this->events($response, $onOutput ?? fn () => null);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $response = static::client($this->config)->get($this->file($path));

        if ($response->notFound()) {
            throw new SandboxException("File [{$path}] does not exist.", $this->provider, $this->id);
        }

        return $this->ensureSuccessful($response)->body();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents): void
    {
        $this->ensureSuccessful(static::client($this->config)->withBody($contents, 'application/octet-stream')->put($this->file($path)));
    }

    /**
     * Read the bridge's server-sent events, whose output is base64 encoded.
     *
     * @param  Closure(string, string): void  $onOutput
     *
     * @throws SandboxException
     */
    protected function events(Response $response, Closure $onOutput): ShellResult
    {
        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        $output = ['stdout' => '', 'stderr' => ''];
        $exit = null;

        try {
            while (true) {
                while (($position = strpos($buffer, "\n\n")) !== false) {
                    $event = $this->parse(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + 2);

                    if ($event['event'] === 'stdout' || $event['event'] === 'stderr') {
                        $chunk = base64_decode($event['data']);

                        $output[$event['event']] .= $chunk;

                        $onOutput($event['event'], $chunk);
                    } elseif ($event['event'] === 'exit') {
                        $exit = json_decode($event['data'], true);
                    } elseif ($event['event'] === 'error') {
                        throw new SandboxException(json_decode($event['data'], true)['error'] ?? 'Cloudflare command failed.', $this->provider, $this->id);
                    }
                }

                if ($body->eof()) {
                    break;
                }

                $buffer .= $body->read(8192);
            }
        } catch (Throwable $exception) {
            // Closing the stream stops reading; the timeout wrapper bounds a command that keeps running in the container...
            $body->close();

            throw $exception;
        }

        if ($exit === null) {
            throw new SandboxException('The bridge closed the command stream without an exit event.', $this->provider, $this->id);
        }

        $exitCode = (int) ($exit['exit_code'] ?? 1);

        return new ShellResult($output['stdout'], $output['stderr'], $exitCode, $this->timedOut($exitCode));
    }

    /**
     * Parse one server-sent event, joining its data lines.
     *
     * @return array{event: string, data: string}
     */
    protected function parse(string $block): array
    {
        $event = 'message';
        $data = [];

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        return ['event' => $event, 'data' => implode("\n", $data)];
    }

    /**
     * Get the bridge route of the given absolute file path.
     */
    protected function file(string $path): string
    {
        return "sandbox/{$this->id}/file".implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * Fail when the bridge returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response): Response
    {
        if ($response->failed()) {
            throw new SandboxException("Cloudflare bridge request failed with status {$response->status()}: ".$response->json('error', $response->body()), $this->provider, $this->id);
        }

        return $response;
    }
}
