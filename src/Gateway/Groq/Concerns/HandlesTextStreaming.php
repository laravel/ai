<?php

namespace Laravel\Ai\Gateway\Groq\Concerns;

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
     * Process a Chat Completions streaming response and yield Laravel stream events.
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
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                // Usage-only chunk (final)...
                if (isset($data['usage'])) {
                    $usage = new Usage(
                        $data['usage']['prompt_tokens'] ?? 0,
                        $data['usage']['completion_tokens'] ?? 0,
                    );
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            // Emit StreamStart on first chunk...
            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Text content delta...
            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Tool call deltas (accumulated by index)...
            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            // Check finish reason...
            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            // Usage in final chunk...
            if (isset($data['usage'])) {
                $usage = new Usage(
                    $data['usage']['prompt_tokens'] ?? 0,
                    $data['usage']['completion_tokens'] ?? 0,
                );
            }
        }

        // Close text if it was started...
        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        // Handle tool calls...
        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($mappedToolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield from $this->handleStreamingToolCalls(
                $invocationId, $provider, $model, $tools, $schema, $options,
                $mappedToolCalls, $currentText, $instructions, $originalMessages,
                $depth, $maxSteps, $priorChatMessages,
            );

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            'stop',
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

        if ($depth + 1 < ($maxSteps ?? count($tools) * 2)) {
            // Build the assistant message with tool calls for this round...
            $assistantMsg = ['role' => 'assistant'];

            if (filled($currentText)) {
                $assistantMsg['content'] = $currentText;
            }

            $assistantMsg['tool_calls'] = array_map(
                fn (ToolCall $tc) => $this->serializeToolCallToChat($tc), $mappedToolCalls
            );

            // Build tool result messages for this round...
            $toolResultMessages = [];

            foreach ($toolResults as $toolResult) {
                $toolResultMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                    'content' => $this->serializeToolResultOutput($toolResult->result),
                ];
            }

            // Accumulate this round's messages for future rounds...
            $updatedPriorMessages = [...$priorChatMessages, $assistantMsg, ...$toolResultMessages];

            // Rebuild full conversation: original messages + all accumulated rounds...
            $chatMessages = [
                ...$this->mapMessagesToChat($originalMessages, $instructions),
                ...$updatedPriorMessages,
            ];

            $body = [
                'model' => $model,
                'messages' => $chatMessages,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ];

            if (filled($tools)) {
                $mappedTools = $this->mapTools($tools);

                if (filled($mappedTools)) {
                    $body['tools'] = $mappedTools;
                    $body['tool_choice'] = 'auto';
                }
            }

            if (filled($schema)) {
                $body['response_format'] = $this->buildResponseFormat($schema);
            }

            $response = $this->withRateLimitHandling(
                $provider->name(),
                fn () => $this->client($provider)
                    ->withOptions(['stream' => true])
                    ->post('chat/completions', $body),
            );

            yield from $this->processTextStream(
                $invocationId, $provider, $model, $tools, $schema, $options,
                $response->getBody(), $instructions, $originalMessages,
                $depth + 1, $maxSteps, $updatedPriorMessages,
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
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $tc) => new ToolCall(
            $tc['id'] ?? '',
            $tc['name'] ?? '',
            json_decode($tc['arguments'] ?? '{}', true) ?? [],
            $tc['id'] ?? null,
        ), array_values($toolCalls));
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
