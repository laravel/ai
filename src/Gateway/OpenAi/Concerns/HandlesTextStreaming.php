<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

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
     * Process an OpenAI streaming response and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        int $depth = 0,
        ?int $maxSteps = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $responseId = '';
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $pendingToolCalls = [];
        $reasoningItems = [];
        $usage = null;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error') {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }

            if ($type === 'response.created' && ! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseId = $data['response']['id'] ?? '';

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['response']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'response.output_text.delta') {
                $textDelta = (string) ($data['delta'] ?? '');

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

                continue;
            }

            if ($type === 'response.output_text.done' && $textStartEmitted) {
                yield (new TextEnd(
                    $this->generateEventId(),
                    $messageId,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'response.reasoning_summary_text.delta') {
                $delta = (string) ($data['delta'] ?? '');

                if ($delta !== '') {
                    if ($reasoningId === '') {
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

                continue;
            }

            if ($type === 'response.output_item.done' && ($data['item']['type'] ?? '') === 'reasoning') {
                $reasoningItems[] = [
                    'id' => $data['item']['id'] ?? null,
                    'summary' => $data['item']['summary'] ?? [],
                ];

                if ($reasoningId !== '') {
                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningId = '';
                }

                continue;
            }

            if ($type === 'response.output_item.done') {
                $itemType = $data['item']['type'] ?? '';

                if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['item']['id'] ?? '',
                        $itemType,
                        $data['item'] ?? [],
                        'completed',
                        time(),
                    ))->withInvocationId($invocationId);

                    continue;
                }
            }

            if (str_starts_with($type, 'response.') && str_contains($type, '_call.')) {
                $parts = explode('.', $type, 3);

                if (count($parts) === 3 && str_ends_with($parts[1], '_call')) {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['item_id'] ?? '',
                        $parts[1],
                        $data,
                        $parts[2],
                        time(),
                    ))->withInvocationId($invocationId);

                    continue;
                }
            }

            if (($data['item']['type'] ?? '') === 'function_call' && $type === 'response.output_item.added') {
                $index = (int) ($data['output_index'] ?? count($pendingToolCalls));

                $toolCall = [
                    'id' => $data['item']['id'] ?? null,
                    'call_id' => $data['item']['call_id'] ?? null,
                    'name' => $data['item']['name'] ?? null,
                    'arguments' => '',
                ];

                if (filled($reasoningItems)) {
                    $latestReasoning = end($reasoningItems);

                    $toolCall['reasoning_id'] = $latestReasoning['id'];
                    $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
                }

                $pendingToolCalls[$index] = $toolCall;

                continue;
            }

            if ($type === 'response.function_call_arguments.delta') {
                $callId = $data['item_id'] ?? null;

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        $call['arguments'] .= $data['delta'] ?? '';
                        break;
                    }
                }
                unset($call);

                continue;
            }

            if ($type === 'response.function_call_arguments.done') {
                $callId = $data['item_id'] ?? null;
                $arguments = $data['arguments'] ?? '';

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        if ($arguments !== '') {
                            $call['arguments'] = $arguments;
                        }

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            new ToolCall(
                                $call['id'],
                                $call['name'],
                                json_decode($call['arguments'] ?? '{}', true) ?? [],
                                $call['call_id'] ?? null,
                                $call['reasoning_id'] ?? null,
                                $call['reasoning_summary'] ?? null,
                            ),
                            time(),
                        ))->withInvocationId($invocationId);

                        break;
                    }
                }

                unset($call);

                continue;
            }

            if ($type === 'response.completed') {
                $response = $data['response'] ?? [];
                $responseId = $response['id'] ?? $responseId;
                $responseUsage = $response['usage'] ?? [];

                $usage = new Usage(
                    ($responseUsage['input_tokens'] ?? 0) - ($responseUsage['input_tokens_details']['cached_tokens'] ?? 0),
                    $responseUsage['output_tokens'] ?? 0,
                    0,
                    $responseUsage['input_tokens_details']['cached_tokens'] ?? 0,
                    $responseUsage['output_tokens_details']['reasoning_tokens'] ?? 0,
                );
            }
        }

        if (filled($pendingToolCalls)) {
            yield from $this->handleStreamingToolCalls(
                $invocationId, $responseId, $provider, $model, $tools, $schema, $options,
                $pendingToolCalls, $currentText, $reasoningItems,
                $depth, $maxSteps,
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
        string $responseId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $pendingToolCalls,
        string $currentText,
        array $reasoningItems,
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

        if ($depth + 1 < ($maxSteps ?? count($tools) * 2)) {
            $body = [
                'model' => $model,
                'previous_response_id' => $responseId,
                'input' => $this->buildToolResultsInput($toolResults),
                'stream' => true,
            ];

            if (filled($tools)) {
                $body['tools'] = $this->mapTools($tools, $provider);
            }

            if (filled($schema)) {
                $body['text'] = $this->buildSchemaFormat($schema);
            }

            $response = $this->withRateLimitHandling(
                $provider->name(),
                fn () => $this->client($provider)
                    ->withOptions(['stream' => true])
                    ->post('responses', $body),
            );

            yield from $this->processTextStream(
                $invocationId, $provider, $model, $tools, $schema, $options,
                $response->getBody(), $depth + 1, $maxSteps,
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
            $tc['call_id'] ?? null,
            $tc['reasoning_id'] ?? null,
            $tc['reasoning_summary'] ?? null,
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
