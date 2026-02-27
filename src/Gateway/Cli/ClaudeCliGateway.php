<?php

namespace Laravel\Ai\Gateway\Cli;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\CliProcessException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ClaudeCliGateway extends CliGateway
{
    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $conversationKey = $this->conversationKey($instructions, $messages);
        $isContinuation = $this->isContinuation($messages);
        $sessionId = $isContinuation ? $this->getSession($conversationKey) : null;

        $command = $this->buildCommand($model, $instructions, $schema, $sessionId, $isContinuation);
        $stdin = $isContinuation && $sessionId
            ? $this->lastUserMessage($messages)
            : $this->formatAllMessages(null, $messages);

        $output = $this->runProcess($command, $stdin, $timeout);

        $parsed = $this->parseJsonOutput($output);

        if (isset($parsed['session_id'])) {
            $this->storeSession($conversationKey, $parsed['session_id']);
        }

        $text = $parsed['result'] ?? $output;

        if ($schema !== null && isset($parsed['structured_output'])) {
            return new StructuredTextResponse(
                $parsed['structured_output'],
                is_array($parsed['structured_output']) ? json_encode($parsed['structured_output']) : (string) $parsed['structured_output'],
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $this->makeTextResponse($text, $provider, $model);
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $conversationKey = $this->conversationKey($instructions, $messages);
        $isContinuation = $this->isContinuation($messages);
        $sessionId = $isContinuation ? $this->getSession($conversationKey) : null;

        $command = $this->buildStreamCommand($model, $instructions, $sessionId, $isContinuation);
        $stdin = $isContinuation && $sessionId
            ? $this->lastUserMessage($messages)
            : $this->formatAllMessages(null, $messages);

        $process = $this->startProcess($command, $stdin, $timeout);

        $messageId = (string) Str::uuid7();
        $buffer = '';

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();

                $incremental = $process->getIncrementalOutput();

                if ($incremental === '') {
                    usleep(10000); // 10ms

                    continue;
                }

                $buffer .= $incremental;

                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $delta = $this->parseStreamLine($line);

                    if ($delta !== null) {
                        yield new TextDelta(
                            id: (string) Str::uuid7(),
                            messageId: $messageId,
                            delta: $delta,
                            timestamp: time(),
                        );
                    }
                }
            }
        } catch (ProcessTimedOutException $e) {
            throw new CliProcessException(
                'Claude CLI streaming timed out after '.$process->getTimeout().' seconds.',
                previous: $e,
            );
        }

        // Capture any output remaining in the process after it exited.
        $buffer .= $process->getIncrementalOutput();

        // Process any remaining buffer.
        if ($buffer !== '') {
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $delta = $this->parseStreamLine($line);

                if ($delta !== null) {
                    yield new TextDelta(
                        id: (string) Str::uuid7(),
                        messageId: $messageId,
                        delta: $delta,
                        timestamp: time(),
                    );
                }
            }

            // Handle any final line without a trailing newline.
            if ($buffer !== '') {
                $delta = $this->parseStreamLine($buffer);

                if ($delta !== null) {
                    yield new TextDelta(
                        id: (string) Str::uuid7(),
                        messageId: $messageId,
                        delta: $delta,
                        timestamp: time(),
                    );
                }
            }
        }

        if (isset($this->sessions['_last_stream'])) {
            $this->storeSession($conversationKey, $this->sessions['_last_stream']);
            unset($this->sessions['_last_stream']);
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw new CliProcessException(
                $stderr ?: 'Claude CLI stream process exited with code '.$process->getExitCode().'.',
            );
        }

        yield new StreamEnd(
            id: (string) Str::uuid7(),
            reason: 'end',
            usage: new Usage,
            timestamp: time(),
        );
    }

    /**
     * Build the command for a non-streaming request.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
     * @return string[]
     */
    protected function buildCommand(string $model, ?string $instructions, ?array $schema, ?string $sessionId, bool $isContinuation): array
    {
        $command = [$this->binary(), '-p', '-', '--output-format', 'json'];

        if ($instructions && ! ($isContinuation && $sessionId)) {
            $command[] = '--system-prompt';
            $command[] = $instructions;
        }

        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        if ($isContinuation && $sessionId) {
            $command[] = '--resume';
            $command[] = $sessionId;
        }

        if ($schema !== null) {
            $command[] = '--json-schema';
            $command[] = json_encode($this->convertSchema($schema));
        }

        return $command;
    }

    /**
     * Build the command for a streaming request.
     *
     * @return string[]
     */
    protected function buildStreamCommand(string $model, ?string $instructions, ?string $sessionId, bool $isContinuation): array
    {
        $command = [$this->binary(), '-p', '-', '--output-format', 'stream-json', '--verbose', '--include-partial-messages'];

        if ($instructions && ! ($isContinuation && $sessionId)) {
            $command[] = '--system-prompt';
            $command[] = $instructions;
        }

        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        if ($isContinuation && $sessionId) {
            $command[] = '--resume';
            $command[] = $sessionId;
        }

        return $command;
    }

    /**
     * Parse the JSON output from Claude CLI.
     *
     * @return array<string, mixed>
     */
    protected function parseJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['result' => $output];
        }

        return $decoded;
    }

    /**
     * Parse a single line from the stream-json output and return the text delta if present.
     */
    protected function parseStreamLine(string $line): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $event = json_decode($line, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Claude stream-json with --include-partial-messages emits stream_event text deltas.
        if (($event['type'] ?? null) === 'stream_event'
            && ($event['event']['delta']['type'] ?? null) === 'text_delta') {
            return $event['event']['delta']['text'] ?? null;
        }

        // Also capture the session_id from result events for session tracking.
        if (($event['type'] ?? null) === 'result' && isset($event['session_id'])) {
            $this->sessions['_last_stream'] = $event['session_id'];
        }

        return null;
    }

    /**
     * Convert a Laravel AI schema array to a JSON Schema array.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>  $schema
     * @return array<string, mixed>
     */
    protected function convertSchema(array $schema): array
    {
        $properties = [];
        $required = [];

        foreach ($schema as $name => $type) {
            $properties[$name] = $type->toArray();
            $required[] = $name;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Get the path to the Claude CLI binary.
     */
    protected function binary(): string
    {
        return $this->config['binary'] ?? 'claude';
    }
}
