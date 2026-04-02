<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
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
     * Process an Anthropic streaming response and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        array $requestBody = [],
        int $depth = 0,
        ?int $maxSteps = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;

        $currentText = '';
        $currentBlockType = '';
        $currentToolIndex = -1;
        $pendingToolCalls = [];
        $responseContent = [];

        $inputTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
        $usage = null;
        $stopReason = '';

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error') {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['type'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }

            if ($type === 'message_start' && ! $streamStartEmitted) {
                $streamStartEmitted = true;

                $messageStartUsage = $data['message']['usage'] ?? [];
                $inputTokens = $messageStartUsage['input_tokens'] ?? 0;
                $cacheCreationTokens = $messageStartUsage['cache_creation_input_tokens'] ?? 0;
                $cacheReadTokens = $messageStartUsage['cache_read_input_tokens'] ?? 0;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['message']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'content_block_start') {
                $blockType = $data['content_block']['type'] ?? '';
                $currentBlockType = $blockType;

                if ($blockType === 'text') {
                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($blockType === 'thinking') {
                    if (! $reasoningStartEmitted) {
                        $reasoningStartEmitted = true;
                        $reasoningId = $this->generateEventId();

                        yield (new ReasoningStart(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($blockType === 'tool_use') {
                    $currentToolIndex++;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $data['content_block']['id'] ?? '',
                        'name' => $data['content_block']['name'] ?? '',
                        'arguments' => '',
                    ];
                } elseif ($blockType === 'server_tool_use') {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'started',
                        time(),
                    ))->withInvocationId($invocationId);
                } elseif ($this->isProviderToolResultBlock($blockType)) {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['tool_use_id'] ?? $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'result_received',
                        time(),
                    ))->withInvocationId($invocationId);
                }

                // Track content blocks for tool loop replay...
                if (isset($data['content_block'])) {
                    $responseContent[$data['index'] ?? count($responseContent)] = $data['content_block'];
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $deltaType = $data['delta']['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $textDelta = (string) ($data['delta']['text'] ?? '');

                    if ($textDelta !== '') {
                        if (! $textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $currentText .= $textDelta;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'thinking_delta') {
                    $delta = (string) ($data['delta']['thinking'] ?? '');

                    if ($delta !== '') {
                        if (! $reasoningStartEmitted) {
                            $reasoningStartEmitted = true;
                            $reasoningId = $this->generateEventId();

                            yield (new ReasoningStart(
                                $this->generateEventId(),
                                $reasoningId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $delta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'input_json_delta' && $currentBlockType === 'tool_use') {
                    $partial = $data['delta']['partial_json'] ?? '';

                    if ($currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop') {
                if ($currentBlockType === 'text' && $textStartEmitted) {
                    yield (new TextEnd(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $textStartEmitted = false;
                } elseif ($currentBlockType === 'thinking' && $reasoningStartEmitted) {
                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningStartEmitted = false;
                    $reasoningId = '';
                } elseif ($currentBlockType === 'tool_use' && $currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                    $call = $pendingToolCalls[$currentToolIndex];
                    $parsedArguments = json_decode($call['arguments'] ?: '{}', true) ?? [];

                    $index = $data['index'] ?? $currentToolIndex;

                    if (isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = $parsedArguments;
                    }

                    // Store parsed arguments to avoid re-decoding in mapStreamToolCalls...
                    $pendingToolCalls[$currentToolIndex]['parsed_arguments'] = $parsedArguments;

                    yield (new ToolCallEvent(
                        $this->generateEventId(),
                        new ToolCall(
                            $call['id'],
                            $call['name'],
                            $parsedArguments,
                            $call['id'],
                        ),
                        time(),
                    ))->withInvocationId($invocationId);
                } elseif ($currentBlockType === 'server_tool_use') {
                    $index = $data['index'] ?? count($responseContent) - 1;

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $responseContent[$index]['id'] ?? '',
                        $currentBlockType,
                        $responseContent[$index] ?? [],
                        'completed',
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentBlockType = '';

                continue;
            }

            if ($type === 'message_delta') {
                $stopReason = $data['delta']['stop_reason'] ?? '';
                $deltaUsage = $data['usage'] ?? [];

                $usage = new Usage(
                    $inputTokens,
                    $deltaUsage['output_tokens'] ?? 0,
                    $cacheCreationTokens,
                    $cacheReadTokens,
                );
            }
        }

        if (filled($pendingToolCalls) && $stopReason === 'tool_use') {
            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $pendingToolCalls,
                $responseContent,
                $requestBody,
                $depth,
                $maxSteps,
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
        array $pendingToolCalls,
        array $responseContent,
        array $requestBody,
        int $depth,
        ?int $maxSteps,
    ): Generator {
        $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

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
            $requestBody['messages'][] = [
                'role' => 'assistant',
                'content' => array_values($responseContent),
            ];

            $toolResultContent = [];

            foreach ($toolResults as $result) {
                $toolResultContent[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $result->id,
                    'content' => $this->serializeToolResultOutput($result->result),
                ];
            }

            $requestBody['messages'][] = [
                'role' => 'user',
                'content' => $toolResultContent,
            ];

            $requestBody['stream'] = true;

            $response = $this->withRateLimitHandling(
                $provider->name(),
                fn () => $this->client($provider)
                    ->withOptions(['stream' => true])
                    ->post('messages', $requestBody),
            );

            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $response->getBody(),
                $requestBody,
                $depth + 1,
                $maxSteps,
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
            $tc['parsed_arguments'] ?? json_decode($tc['arguments'] ?? '{}', true) ?? [],
            $tc['id'] ?? null,
        ), array_values($toolCalls));
    }

    /**
     * Determine if the given block type is a provider tool result.
     */
    protected function isProviderToolResultBlock(string $blockType): bool
    {
        return str_ends_with($blockType, '_tool_result');
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
