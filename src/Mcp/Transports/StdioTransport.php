<?php

namespace Laravel\Ai\Mcp\Transports;

use Laravel\Ai\Mcp\Exceptions\McpException;

class StdioTransport implements McpTransport
{
    /**
     * The subprocess resource.
     *
     * @var resource|null
     */
    protected $process = null;

    /**
     * The subprocess pipe resources.
     *
     * @var array<int, resource>
     */
    protected array $pipes = [];

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    public function __construct(
        protected array $command,
        protected array $env = [],
        protected int $timeout = 30,
    ) {}

    /**
     * Open the stdio subprocess.
     */
    public function open(): void
    {
        if ($this->isOpen()) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        ];

        $environment = filled($this->env)
            ? array_merge(getenv() ?: [], $this->env)
            : null;

        $this->process = proc_open($this->command, $descriptors, $this->pipes, null, $environment);

        if (! is_resource($this->process)) {
            throw McpException::connectionFailed($this->serverName(), 'Failed to start subprocess.');
        }

        stream_set_blocking($this->pipes[1], false);
    }

    /**
     * Send a JSON-RPC request and wait for the matching response.
     */
    public function send(array $request): array
    {
        $this->ensureOpen();

        $this->write($request);

        return $this->readResponse($request['id']);
    }

    /**
     * Send a JSON-RPC notification.
     */
    public function notify(array $notification): void
    {
        $this->ensureOpen();

        $this->write($notification);
    }

    /**
     * Close the subprocess.
     */
    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            fclose($this->pipes[1]);
        }

        $this->pipes = [];

        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                proc_close($this->process);
                $this->process = null;

                return;
            }

            usleep(50_000);
        }

        proc_terminate($this->process);

        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                proc_close($this->process);
                $this->process = null;

                return;
            }

            usleep(50_000);
        }

        proc_terminate($this->process, 9);
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * Determine if the subprocess is running.
     */
    public function isOpen(): bool
    {
        if (! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    /**
     * Write a newline-delimited JSON-RPC message to stdin.
     */
    protected function write(array $message): void
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $written = @fwrite($this->pipes[0], $json."\n");

        if ($written === false) {
            throw McpException::connectionFailed($this->serverName(), 'Failed to write to process stdin.');
        }

        @fflush($this->pipes[0]);
    }

    /**
     * Read a JSON-RPC response matching the request ID from stdout.
     */
    protected function readResponse(string|int $requestId): array
    {
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            $remaining = max(0.1, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);

            $read = [$this->pipes[1]];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            $line = @fgets($this->pipes[1]);

            if ($line === false) {
                if (! $this->isOpen()) {
                    $status = is_resource($this->process) ? proc_get_status($this->process) : [];

                    throw McpException::processExited($this->serverName(), $status['exitcode'] ?? -1);
                }

                continue;
            }

            $decoded = json_decode(trim($line), true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['id'] ?? null) === $requestId) {
                return $decoded;
            }
        }

        throw McpException::timedOut($this->serverName(), $this->timeout);
    }

    /**
     * Ensure the subprocess is open.
     */
    protected function ensureOpen(): void
    {
        if (! $this->isOpen()) {
            throw McpException::connectionFailed($this->serverName(), 'Transport is not open.');
        }
    }

    /**
     * Get a display name for the subprocess.
     */
    protected function serverName(): string
    {
        return implode(' ', $this->command);
    }

    /**
     * Close the transport when discarded.
     */
    public function __destruct()
    {
        $this->close();
    }
}
