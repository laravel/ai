<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

trait HandlesTextStreaming
{
    /**
     * Process an Ollama NDJSON streaming response and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        ?string $instructions = null,
        array $originalMessages = [],
        int $depth = 0,
        ?int $maxSteps = null,
        array $priorChatMessages = [],
        ?int $timeout = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $pendingToolCalls = [];
        $usage = null;
        $lastData = [];

        foreach ($this->parseNdjsonStream($streamBody) as $data) {
            if (isset($data['error'])) {
                $error = $data['error'];
                $isStructured = is_array($error);

                yield (new Error(
                    $this->generateEventId(),
                    $isStructured ? ($error['code'] ?? 'unknown_error') : 'unknown_error',
                    $isStructured ? ($error['message'] ?? 'Unknown error') : (string) $error,
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $content = $data['message']['content'] ?? '';

            if ($content !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $content;

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $content,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Accumulate tool calls across chunks. Ollama's docs tell clients to accumulate
            // partial fields - so we'll merge id /name / arguments per-index rather than
            // only storing the first chunk of the tool calls when we handle this here.
            if (! empty($data['message']['tool_calls'])) {
                foreach ($data['message']['tool_calls'] as $index => $toolCall) {
                    if (! isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index] = [
                            'id' => null,
                            'name' => '',
                            'arguments' => [],
                            'argumentsBuffer' => '',
                        ];
                    }

                    if (isset($toolCall['id'])) {
                        $pendingToolCalls[$index]['id'] = $toolCall['id'];
                    }

                    $name = $toolCall['function']['name'] ?? '';

                    if ($name !== '') {
                        $pendingToolCalls[$index]['name'] = $name;
                    }

                    if (array_key_exists('arguments', $toolCall['function'] ?? [])) {
                        $arguments = $toolCall['function']['arguments'];

                        if (is_array($arguments)) {
                            $pendingToolCalls[$index]['arguments'] = array_replace(
                                $pendingToolCalls[$index]['arguments'],
                                $arguments
                            );
                        } elseif (is_string($arguments)) {
                            $pendingToolCalls[$index]['argumentsBuffer'] .= $arguments;
                        }
                    }
                }
            }

            if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
                $usage = new Usage(
                    $data['prompt_eval_count'] ?? 0,
                    $data['eval_count'] ?? 0,
                );
            }

            if ($data['done'] ?? false) {
                $lastData = $data;

                break;
            }
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls)) {
            $mappedToolCalls = array_map(function (array $toolCall) {
                $arguments = $toolCall['arguments'];

                if ($toolCall['argumentsBuffer'] !== '') {
                    $decoded = json_decode($toolCall['argumentsBuffer'], true);

                    if (is_array($decoded)) {
                        $arguments = array_replace($arguments, $decoded);
                    }
                }

                $id = $toolCall['id'] ?? (string) Str::uuid7();

                return new ToolCall($id, $toolCall['name'], $arguments, $id);
            }, array_values($pendingToolCalls));

            foreach ($mappedToolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $mappedToolCalls,
                $currentText,
                $instructions,
                $originalMessages,
                $depth,
                $maxSteps,
                $priorChatMessages,
                $timeout,
            );

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason($lastData)->value,
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Handle tool calls detected during streaming.
     */
    protected function handleStreamingToolCalls(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $mappedToolCalls,
        string $currentText,
        ?string $instructions,
        array $originalMessages,
        int $depth,
        ?int $maxSteps,
        array $priorChatMessages,
        ?int $timeout = null,
    ): Generator {
        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $toolResult = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );

            $toolResults[] = $toolResult;

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $toolResult,
                true,
                null,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($depth + 1 < ($maxSteps ?? round(count($tools) * 1.5))) {
            $assistantMsg = [
                'role' => 'assistant',
                'content' => $currentText,
                'tool_calls' => array_map(
                    fn (ToolCall $toolCall) => $this->serializeToolCallToChat($toolCall), $mappedToolCalls
                ),
            ];

            $toolResultMessages = array_map(fn (ToolResult $toolResult) => [
                'role' => 'tool',
                'tool_name' => $toolResult->name,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ], $toolResults);

            $updatedPriorMessages = [...$priorChatMessages, $assistantMsg, ...$toolResultMessages];

            $chatMessages = [
                ...$this->mapMessagesToChat($originalMessages, $instructions),
                ...$updatedPriorMessages,
            ];

            $body = $this->buildChatRequestBody(
                $provider,
                $model,
                $chatMessages,
                $tools,
                $schema,
                $options,
                stream: true,
            );

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post('api/chat', $body),
            );

            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $response->getBody(),
                $instructions,
                $originalMessages,
                $depth + 1,
                $maxSteps,
                $updatedPriorMessages,
                $timeout,
            );
        } else {
            yield (new StreamEnd(
                $this->generateEventId(),
                'stop',
                new Usage(0, 0),
                time(),
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Parse an Ollama NDJSON stream, yielding each parsed JSON object.
     */
    protected function parseNdjsonStream($streamBody): Generator
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $buffer .= $streamBody->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);

                if ($data !== null) {
                    yield $data;
                }
            }
        }

        if (filled(trim($buffer))) {
            $data = json_decode(trim($buffer), true);

            if ($data !== null) {
                yield $data;
            }
        }
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
