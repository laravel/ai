<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Providers\Provider;
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
     * Process an Anthropic streaming response for a single turn and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;

        $currentText = '';
        $currentBlockType = '';
        $currentBlockIndex = -1;
        $currentBlockText = '';
        $currentThinkingText = '';
        $currentSignature = '';
        $currentToolIndex = -1;
        $currentServerToolInput = '';
        $pendingToolCalls = [];
        $responseContent = [];

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
                $currentBlockIndex = $data['index'] ?? count($responseContent);

                if ($blockType === 'text') {
                    $currentBlockText = '';

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
                        if ($event = $emitTextStart()) {
                            yield $event;
                        }

                        $currentText .= $textDelta;
                        $currentBlockText .= $textDelta;

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
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['text'] = $currentBlockText;
                    }

                    yield (new TextEnd(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $textStartEmitted = false;
                } elseif ($currentBlockType === 'thinking' && $reasoningStartEmitted) {
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['thinking'] = $currentThinkingText;
                        $responseContent[$currentBlockIndex]['signature'] = $currentSignature;
                    }

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

                    $pendingToolCalls[$currentToolIndex]['parsed_arguments'] = $parsedArguments;

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
                } elseif ($currentBlockType === 'server_tool_use') {
                    $index = $data['index'] ?? count($responseContent) - 1;

                    if ($currentServerToolInput !== '' && isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = json_decode($currentServerToolInput, true) ?? [];
                    }

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

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason(['stop_reason' => $stopReason])->value,
            $usage ?? new Usage(0, 0),
            time(),
            providerContentBlocks: $responseContent,
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
