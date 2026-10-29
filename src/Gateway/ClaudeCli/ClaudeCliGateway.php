<?php

namespace Laravel\Ai\Gateway\ClaudeCli;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\ClaudeCliProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

class ClaudeCliGateway implements StepTextGateway
{
    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Generate text for a single Claude CLI step by consuming the streaming generator.
     *
     * @param  Message[]  $messages
     * @param  array  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $invocationId = strtolower((string) Str::uuid7());

        $generator = $this->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );

        foreach ($generator as $event) {
            // consume — we only need the return value
        }

        // $generator->getReturn() is always non-null here: if the generator threw,
        // the exception propagated out of the foreach above before reaching this line.
        // The ?? branch is intentionally unreachable and exists only to satisfy static analysis.
        return $generator->getReturn() ?? new StepResponse(
            text: '',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
        );
    }

    /**
     * Stream text for a single Claude CLI step.
     *
     * Spawns a `claude --output-format stream-json` subprocess and yields
     * StreamEvents as JSONL lines arrive on stdout.
     *
     * @param  Message[]  $messages
     * @param  array  $tools  Ignored — the Claude CLI exposes its own built-in tools (Bash, WebSearch, etc.)
     *                        internally; PHP-defined tools are not forwarded to the subprocess.
     * @param  array<string, mixed>|null  $schema
     * @return Generator<int, \Laravel\Ai\Streaming\Events\StreamEvent, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        if ($schema !== null) {
            throw new InvalidArgumentException('The Claude CLI provider does not support structured output.');
        }

        $sessionId = $stepContext->continuationToken;
        $prompt = $this->extractPrompt($messages, resuming: $sessionId !== null);

        $command = $this->buildCommand($provider, $model, $instructions, $prompt, $options, $sessionId);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['file', '/dev/null', 'w'],  // stderr: discarded to prevent pipe-buffer deadlock with --verbose
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the Claude CLI process.');
        }

        fclose($pipes[0]);

        // Per-read timeout: each fgets call blocks for at most $timeoutSeconds before returning false.
        // Wall-clock deadline: also enforced below so a slow-drip process cannot run indefinitely.
        $timeoutSeconds = $timeout ?? 300;
        stream_set_timeout($pipes[1], $timeoutSeconds);
        $deadline = time() + $timeoutSeconds;

        $messageId = strtolower((string) Str::uuid7());
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $accumulatedText = '';
        $finalSessionId = null;
        $errorEvent = null;

        try {
        while (! feof($pipes[1])) {
            $line = fgets($pipes[1]);

            // fgets returns false on per-read timeout or stream error
            if ($line === false) {
                $meta = stream_get_meta_data($pipes[1]);

                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('Claude CLI process timed out after '.$timeoutSeconds.' seconds.');
                }

                break;
            }

            // Wall-clock deadline check: catches slow-drip processes that never hit the per-read timeout
            if (time() >= $deadline) {
                throw new RuntimeException('Claude CLI process exceeded the '.$timeoutSeconds.' second wall-clock deadline.');
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (! is_array($data)) {
                continue;
            }

            $type = $data['type'] ?? '';
            $subtype = $data['subtype'] ?? '';

            // system/init — session established
            if ($type === 'system' && $subtype === 'init') {
                $finalSessionId = $data['session_id'] ?? null;

                if (! $streamStartEmitted) {
                    $streamStartEmitted = true;

                    yield (new StreamStart(
                        strtolower((string) Str::uuid7()),
                        $provider->name(),
                        $model,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            // assistant message with text content
            if ($type === 'assistant') {
                $content = $data['message']['content'] ?? [];

                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'text') {
                        continue;
                    }

                    $text = (string) ($block['text'] ?? '');

                    if ($text === '') {
                        continue;
                    }

                    if (! $streamStartEmitted) {
                        $streamStartEmitted = true;

                        yield (new StreamStart(
                            strtolower((string) Str::uuid7()),
                            $provider->name(),
                            $model,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            strtolower((string) Str::uuid7()),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    $accumulatedText .= $text;

                    yield (new TextDelta(
                        strtolower((string) Str::uuid7()),
                        $messageId,
                        $text,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            // result — terminal event
            if ($type === 'result') {
                $finalSessionId ??= $data['session_id'] ?? null;

                if ($subtype === 'error') {
                    $errorEvent = new ErrorEvent(
                        strtolower((string) Str::uuid7()),
                        'cli_error',
                        $data['error'] ?? 'An unknown error occurred.',
                        false,
                        time(),
                    );

                    yield $errorEvent->withInvocationId($invocationId);
                }

                continue;
            }
        }
        } finally {
            // Always release resources — runs even if the generator is abandoned mid-iteration
            fclose($pipes[1]);
            proc_terminate($process, 9);
            proc_close($process);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                strtolower((string) Str::uuid7()),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($errorEvent !== null) {
            throw new StreamErrorException($errorEvent);
        }

        return new StepResponse(
            text: $accumulatedText,
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
            continuationToken: $finalSessionId,
        );
    }

    /**
     * Extract the prompt string from the message array.
     *
     * When resuming a session, only the last user message is passed — the CLI
     * owns the conversation history. When starting fresh, the entire conversation
     * is formatted as a single prompt string.
     *
     * @param  Message[]  $messages
     */
    protected function extractPrompt(array $messages, bool $resuming): string
    {
        if ($resuming) {
            // Walk backwards to find the last user message
            foreach (array_reverse($messages) as $message) {
                if ($message instanceof UserMessage) {
                    return $this->contentToText($message->content);
                }
            }

            throw new InvalidArgumentException('Cannot resume a session: no UserMessage found in the message history.');
        }

        // Format the full history as a single text prompt.
        // ToolCallMessage and ToolResultMessage are intentionally omitted — multi-step tool histories
        // should use session resumption via a continuation token rather than inline history replay.
        $parts = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $parts[] = 'Human: '.$this->contentToText($message->content);
            } elseif ($message instanceof AssistantMessage) {
                $parts[] = 'Assistant: '.$this->contentToText($message->content);
            }
        }

        if ($parts === []) {
            throw new InvalidArgumentException('Cannot generate text with no messages.');
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert a message's content (string or multi-part block array) to a plain text string.
     *
     * @param  string|array<int, mixed>  $content
     */
    protected function contentToText(mixed $content): string
    {
        if (is_array($content)) {
            $texts = [];

            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $texts[] = (string) ($block['text'] ?? '');
                }
            }

            return implode(' ', $texts);
        }

        return (string) $content;
    }

    /**
     * Build the CLI command array.
     *
     * @param  array<string, mixed>|null  $options
     */
    protected function buildCommand(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        string $prompt,
        ?TextGenerationOptions $options,
        ?string $sessionId,
    ): array {
        /** @var ClaudeCliProvider $provider */
        $binary = $provider->cliBinary();

        $command = [
            $binary,
            '--output-format', 'stream-json',
            '--verbose',
            '--model', $model,
        ];

        if (filled($instructions)) {
            $command[] = '--system-prompt';
            $command[] = $instructions;
        }

        if ($sessionId !== null) {
            $command[] = '--resume';
            $command[] = $sessionId;
        }

        $mcpConfig = $this->buildMcpConfig($provider, $options);

        if ($mcpConfig !== null) {
            $encoded = json_encode($mcpConfig);
            if ($encoded === false) {
                throw new \RuntimeException('Failed to encode MCP configuration as JSON: ' . json_last_error_msg());
            }
            $command[] = '--mcp-config';
            $command[] = $encoded;
        }

        $command[] = '--print';
        // Array-based proc_open passes $prompt as a literal argument value, not through a shell,
        // so a prompt starting with "--" is safe and is never misinterpreted as a flag.
        $command[] = $prompt;

        return $command;
    }

    /**
     * Assemble the MCP server configuration from static provider config and per-call providerOptions.
     *
     * Static single server:  config['mcp_url']        → key "{name}-static"
     * Static multiple servers: config['mcp_servers']  → keys "{name}-static-{n}"
     * Per-call servers:  providerOptions['mcp_servers'] → keys "{name}-call-{n}"
     *
     * Returns null when no MCP servers are configured.
     *
     * @return array<string, mixed>|null
     */
    protected function buildMcpConfig(TextProvider $provider, ?TextGenerationOptions $options): ?array
    {
        $servers = [];
        $name = $provider->name();

        // Single static URL shorthand
        if ($provider instanceof ClaudeCliProvider && filled($provider->staticMcpUrl())) {
            $servers["{$name}-static"] = [
                'type' => 'sse',
                'url' => $provider->staticMcpUrl(),
            ];
        }

        // Multiple static servers from config
        if ($provider instanceof ClaudeCliProvider) {
            foreach (array_values($provider->staticMcpServers()) as $i => $server) {
                $n = $i + 1;
                $servers["{$name}-static-{$n}"] = $server;
            }
        }

        // Per-call MCP servers from providerOptions
        $perCallServers = $options?->providerOptions('claude-cli')['mcp_servers'] ?? [];

        foreach (array_values($perCallServers) as $i => $server) {
            $n = $i + 1;
            $servers["{$name}-call-{$n}"] = $server;
        }

        if ($servers === []) {
            return null;
        }

        return ['mcpServers' => $servers];
    }
}
