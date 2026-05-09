<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
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

trait HandlesTextStreaming
{
    /**
     * Resume a paused server-side loop by replaying the assistant response
     * as-is and continuing to stream the follow-up response.
     */
    protected function resumeFromPauseTurn(
        string $invocationId,
        Provider $provider,
        string $model,
        array $requestBody,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $maxResumes = $options?->maxSteps ?? 5;

        for ($depth = 0; $depth < $maxResumes; $depth++) {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post('messages', $requestBody),
            );

            $responseContent = [];
            $stopReason = '';
            $bufferedStreamEnd = null;

            foreach ($this->processTextStream($invocationId, $provider, $model, $response->getBody(), $responseContent, $stopReason) as $event) {
                if ($event instanceof StreamEnd) {
                    $bufferedStreamEnd = $event;
                } else {
                    yield $event;
                }
            }

            if ($stopReason !== 'pause_turn') {
                if ($bufferedStreamEnd) {
                    yield $bufferedStreamEnd;
                }

                return;
            }

            $requestBody['messages'][] = [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject(array_values($responseContent)),
            ];
        }
    }

    /**
     * Process an Anthropic streaming response for a single turn and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
        array &$outResponseContent = [],
        string &$outStopReason = '',
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;

        $currentBlockType = '';
        $currentThinkingText = '';
        $currentSignature = '';
        $currentToolIndex = -1;
        $currentServerToolInput = '';
        $pendingToolCalls = [];
        $responseContent = [];
        $currentServerToolBlock = [];

        $inputTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
        $usage = null;
        $stopReason = '';

        $emitTextStart = function () use (&$textStartEmitted, $messageId, $invocationId) {
            if ($textStartEmitted) {
                return null;
            }

            $textStartEmitted = true;

            return (new TextStart(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningStartEmitted, &$reasoningId, $invocationId) {
            if ($reasoningStartEmitted) {
                return null;
            }

            $reasoningStartEmitted = true;
            $reasoningId = $this->generateEventId();

            return (new ReasoningStart(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        };

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
                $index = $data['index'] ?? count($responseContent);
                $responseContent[$index] = $data['content_block'] ?? [];

                if ($blockType === 'text') {
                    if ($event = $emitTextStart()) {
                        yield $event;
                    }
                } elseif ($blockType === 'thinking') {
                    $currentThinkingText = '';
                    $currentSignature = '';

                    if ($event = $emitReasoningStart()) {
                        yield $event;
                    }
                } elseif ($blockType === 'tool_use') {
                    $currentToolIndex++;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $data['content_block']['id'] ?? '',
                        'name' => $data['content_block']['name'] ?? '',
                        'arguments' => '',
                    ];
                } elseif ($blockType === 'server_tool_use') {
                    $currentServerToolInput = '';
                    $currentServerToolBlock = $data['content_block'] ?? [];

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $currentServerToolBlock['id'] ?? '',
                        $blockType,
                        $currentServerToolBlock,
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

                continue;
            }

            if ($type === 'content_block_delta') {
                $deltaType = $data['delta']['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $textDelta = (string) ($data['delta']['text'] ?? '');

                    if ($textDelta !== '') {
                        $blockIndex = $data['index'] ?? array_key_last($responseContent);
                        if ($blockIndex !== null && isset($responseContent[$blockIndex])) {
                            $responseContent[$blockIndex]['text'] = ($responseContent[$blockIndex]['text'] ?? '').$textDelta;
                        }

                        if ($event = $emitTextStart()) {
                            yield $event;
                        }

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
                        if ($event = $emitReasoningStart()) {
                            yield $event;
                        }

                        $currentThinkingText .= $delta;

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $delta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    $currentSignature .= (string) ($data['delta']['signature'] ?? '');
                } elseif ($deltaType === 'citations_delta' && $currentBlockType === 'text') {
                    $citationData = $data['delta']['citation'] ?? null;

                    if ($citationData && ($citationData['type'] ?? '') === 'web_search_result_location') {
                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation($citationData['url'] ?? '', $citationData['title'] ?? null),
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $partial = (string) ($data['delta']['partial_json'] ?? '');

                    if ($currentBlockType === 'tool_use' && $currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;
                    } elseif ($currentBlockType === 'server_tool_use') {
                        $currentServerToolInput .= $partial;
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

                    // Don't emit the synthetic structured output tool call as a real tool call.
                    if ($call['name'] !== 'output_structured_data') {
                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            new ToolCall(
                                $call['id'],
                                $call['name'],
                                $parsedArguments,
                                $call['id'],
                                reasoningSummary: $currentThinkingText !== '' ? [$currentThinkingText] : null,
                                reasoningSignature: $currentSignature ?: null,
                            ),
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($currentBlockType === 'server_tool_use') {
                    $blockIndex = $data['index'] ?? array_key_last($responseContent);

                    if ($currentServerToolInput !== '') {
                        $decodedInput = json_decode($currentServerToolInput, true) ?? [];
                        $currentServerToolBlock['input'] = $decodedInput;

                        if ($blockIndex !== null && isset($responseContent[$blockIndex])) {
                            $responseContent[$blockIndex]['input'] = $decodedInput;
                        }
                    }

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $currentServerToolBlock['id'] ?? '',
                        $currentBlockType,
                        $currentServerToolBlock,
                        'completed',
                        time(),
                    ))->withInvocationId($invocationId);

                    $currentServerToolBlock = [];
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

        $outResponseContent = $responseContent;
        $outStopReason = $stopReason;

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason(['stop_reason' => $stopReason])->value,
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
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
