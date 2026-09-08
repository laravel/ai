<?php

namespace Laravel\Ai\Harness\ClaudeCode;

use Generator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Harness\Runtime;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Harness\HarnessException;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\PermissionMode;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Mcp\Server;
use LogicException;
use Throwable;

use function Laravel\Ai\ulid;

class ClaudeCodeRuntime implements Runtime
{
    public function __construct(protected array $config) {}

    public function command(HarnessRun $run): array
    {
        $command = [
            $this->config['binary'] ?? 'claude', '-p', '--output-format', 'stream-json', '--verbose',
            '--include-partial-messages', '--model', $run->model,
            $run->resume ? '--resume' : '--session-id', $run->sessionId,
            '--permission-mode', $run->permissionMode->value,
            '--max-turns', (string) ($this->config['max_turns'] ?? 50),
        ];

        if ($run->instructions !== '') {
            array_push($command, '--append-system-prompt', $run->instructions);
        }

        if ($this->needsMcp($run)) {
            array_push($command, '--mcp-config', json_encode(['mcpServers' => ['laravel' => [
                'type' => 'stdio', 'command' => PHP_BINARY,
                'args' => [base_path('artisan'), 'ai:harness-mcp', $run->id, '--no-ansi'],
            ]]], JSON_THROW_ON_ERROR));
        }

        if ($run->permissionMode !== PermissionMode::BypassPermissions) {
            array_push($command, '--permission-prompt-tool', 'mcp__laravel__approve');
        }

        return $command;
    }

    public function run(HarnessRun $run): Generator
    {
        $cache = Cache::store($this->config['cache_store'] ?? null);
        $store = new ApprovalStore($cache, $this->config['approval_ttl'] ?? 3600);
        $process = null;
        $buffer = '';
        $lines = new \SplQueue;
        $parser = new StreamJsonParser($run);

        try {
            if ($this->needsMcp($run)) {
                if (! class_exists(Server::class)) {
                    throw new LogicException('Install laravel/mcp to expose harness tools or request approvals.');
                }

                if ($cache->getStore() instanceof ArrayStore || $cache->getStore() instanceof NullStore) {
                    throw new LogicException('Harness MCP requires a cache store shared with the Artisan subprocess.');
                }

                $cache->put('ai:harness:run:'.$run->id, $run, $run->timeout + 30);
                // The subprocess must discover the selected cache store before it can load the run.
                $cacheName = $this->config['cache_store'] ?? config('cache.default');
            }

            $env = $this->config['env'] ?? [];

            if (! empty($this->config['key'])) {
                $env['ANTHROPIC_API_KEY'] = $this->config['key'];
            }

            if (isset($cacheName)) {
                $env['AI_HARNESS_RUN_CACHE_STORE'] = $cacheName;
            }

            $process = (new Factory)->newPendingProcess()->path($run->cwd)->env($env)->timeout($run->timeout)->input($run->prompt)
                ->start($this->command($run), function ($type, $output) use (&$buffer, $lines) {
                    if ($type !== 'out') {
                        return;
                    }

                    $buffer .= $output;

                    while (($position = strpos($buffer, "\n")) !== false) {
                        $lines->enqueue(substr($buffer, 0, $position));
                        $buffer = substr($buffer, $position + 1);
                    }
                });

            do {
                $running = $process->running();
                $process->ensureNotTimedOut();

                while (! $lines->isEmpty()) {
                    foreach ($parser->parse($lines->dequeue()) as $event) {
                        if (! $this->isPendingToolResult($event, $store, $run)) {
                            yield $event;
                        }
                    }
                }

                if ($running) {
                    usleep(10000);
                }
            } while ($running);

            $exit = $process->wait();

            while (! $lines->isEmpty()) {
                foreach ($parser->parse($lines->dequeue()) as $event) {
                    if (! $this->isPendingToolResult($event, $store, $run)) {
                        yield $event;
                    }
                }
            }

            foreach ($parser->parse($buffer) as $event) {
                if (! $this->isPendingToolResult($event, $store, $run)) {
                    yield $event;
                }
            }

            if (! $exit->successful()) {
                throw new HarnessException('Claude Code exited with code '.$exit->exitCode().': '.trim($exit->errorOutput()), $run->sessionId);
            }

            $result = $parser->result ?? throw new HarnessException('Claude Code exited without a result message.', $run->sessionId);
            $result->pendingApprovals = $store->pending($run)->map(function (PendingApproval $approval) use ($result) {
                $call = $result->toolCalls->last(fn ($call) => $call->name === $approval->tool && $call->arguments === $approval->arguments);

                return new PendingApproval($approval->id, $approval->tool, $approval->arguments, $approval->reason, $call?->id);
            });

            if ($result->pendingApprovals->isNotEmpty()) {
                $result->finishReason = 'tool_approval';
                yield new ToolApprovalRequest(ulid(), $result->pendingApprovals, time());
            }

            yield new StreamEnd(ulid(), $result->finishReason, $result->usage, time(), $result->meta);

            return $result;
        } catch (Throwable $exception) {
            yield new Error(ulid(), 'harness_error', $exception->getMessage(), false, time(), ['session_id' => $run->sessionId]);

            throw $exception instanceof HarnessException ? $exception : new HarnessException($exception->getMessage(), $run->sessionId, $exception);
        } finally {
            if ($process?->running()) {
                $process->stop(0);
            }

            $cache->forget('ai:harness:run:'.$run->id);
        }
    }

    protected function isPendingToolResult(StreamEvent $event, ApprovalStore $store, HarnessRun $run): bool
    {
        return $event instanceof ToolResult && $store->pending($run)->contains(
            fn (PendingApproval $approval) => $approval->tool === $event->toolResult->name && $approval->arguments === $event->toolResult->arguments,
        );
    }

    protected function needsMcp(HarnessRun $run): bool
    {
        return $run->tools !== [] || $run->permissionMode !== PermissionMode::BypassPermissions;
    }
}
