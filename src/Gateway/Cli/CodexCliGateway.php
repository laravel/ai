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

class CodexCliGateway extends CliGateway
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

        $parsed = $this->parseOutput($output);

        if (isset($parsed['session_id'])) {
            $this->storeSession($conversationKey, $parsed['session_id']);
        }

        $text = $parsed['text'] ?? $output;

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
        $command = $this->buildCommand($model, $instructions, $schema, null, false, streaming: true);
        $stdin = $this->formatAllMessages(null, $messages);

        $process = $this->startProcess($command, $stdin, $timeout);

        $messageId = (string) Str::uuid7();
        $buffer = '';

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();

                $incremental = $process->getIncrementalOutput();

                if ($incremental === '') {
                    usleep(10000);

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
                'Codex CLI streaming timed out after '.$process->getTimeout().' seconds.',
                previous: $e,
            );
        }

        // Capture any output remaining in the process after it exited.
        $buffer .= $process->getIncrementalOutput();

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

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw new CliProcessException(
                $stderr ?: 'Codex CLI stream process exited with code '.$process->getExitCode().'.',
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
     * Build the command for a Codex CLI request.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
     * @return string[]
     */
    protected function buildCommand(string $model, ?string $instructions, ?array $schema, ?string $sessionId, bool $isContinuation, bool $streaming = false): array
    {
        if ($isContinuation && $sessionId) {
            $command = [$this->binary(), 'exec', 'resume', $sessionId];
        } else {
            $command = [$this->binary(), 'exec', '-'];
        }

        $command[] = '--json';
        $command[] = '--skip-git-repo-check';

        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        if ($instructions && ! ($isContinuation && $sessionId)) {
            $command[] = '--system-prompt';
            $command[] = $instructions;
        }

        if ($schema !== null) {
            $command[] = '--output-schema';
            $command[] = json_encode($this->convertSchema($schema));
        }

        return $command;
    }

    /**
     * Parse the JSONL output from Codex CLI.
     *
     * @return array{text: string, session_id: ?string, structured_output: ?array<string, mixed>}
     */
    protected function parseOutput(string $output): array
    {
        $text = '';
        $sessionId = null;
        $structuredOutput = null;

        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $text .= $line;

                continue;
            }

            if (($event['type'] ?? null) === 'message' && isset($event['content'])) {
                $text .= is_array($event['content']) ? json_encode($event['content']) : $event['content'];
            }

            if (isset($event['session_id'])) {
                $sessionId = $event['session_id'];
            }

            if (isset($event['structured_output'])) {
                $structuredOutput = $event['structured_output'];
            }
        }

        return [
            'text' => $text !== '' ? $text : $output,
            'session_id' => $sessionId,
            'structured_output' => $structuredOutput,
        ];
    }

    /**
     * Parse a single JSONL line from Codex streaming output.
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

        if (($event['type'] ?? null) === 'message' && isset($event['content'])) {
            return is_array($event['content']) ? json_encode($event['content']) : $event['content'];
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
     * Get the path to the Codex CLI binary.
     */
    protected function binary(): string
    {
        return $this->config['binary'] ?? 'codex';
    }
}
