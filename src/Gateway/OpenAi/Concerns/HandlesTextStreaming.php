<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
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
        ?int $timeout = null,
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
        $responseData = [];

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
                    $this->appendReasoningSummaryDelta($reasoningItems, $data);

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

            if ($type === 'response.reasoning_summary_text.done') {
                $this->recordReasoningSummaryText($reasoningItems, $data);

                continue;
            }

            if ($type === 'response.output_item.added' && ($data['item']['type'] ?? '') === 'reasoning') {
                $this->recordReasoningItem($reasoningItems, $data['item'], $data['output_index'] ?? null);

                continue;
            }

            if ($type === 'response.output_item.done' && ($data['item']['type'] ?? '') === 'reasoning') {
                $this->recordReasoningItem($reasoningItems, $data['item'], $data['output_index'] ?? null);

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

                if ($itemType === 'function_call') {
                    $index = (int) ($data['output_index'] ?? count($pendingToolCalls));

                    $this->upsertPendingToolCall($pendingToolCalls, $index, $data['item'] ?? []);
                    $this->attachLatestReasoning($pendingToolCalls[$index], $reasoningItems);

                    continue;
                }

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

                $this->upsertPendingToolCall($pendingToolCalls, $index, $data['item']);
                $this->attachLatestReasoning($pendingToolCalls[$index], $reasoningItems);

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
                $matched = false;

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        $matched = true;
                        $call['output_index'] = $data['output_index'] ?? ($call['output_index'] ?? null);

                        if ($arguments !== '') {
                            $call['arguments'] = $arguments;
                        }

                        $this->attachLatestReasoning($call, $reasoningItems);

                        break;
                    }
                }

                unset($call);

                if (! $matched) {
                    $index = (int) ($data['output_index'] ?? count($pendingToolCalls));

                    $this->upsertPendingToolCall($pendingToolCalls, $index, [
                        'id' => $callId,
                        'call_id' => $data['call_id'] ?? null,
                        'name' => $data['name'] ?? null,
                        'arguments' => $arguments,
                    ]);
                    $this->attachLatestReasoning($pendingToolCalls[$index], $reasoningItems);
                }

                continue;
            }

            if ($type === 'response.completed') {
                $response = $data['response'] ?? [];
                $responseData = $response;
                $responseId = $response['id'] ?? $responseId;
                $responseUsage = $response['usage'] ?? [];

                $this->syncPendingToolCallsFromOutput($pendingToolCalls, $response['output'] ?? []);

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
                $invocationId,
                $responseId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $pendingToolCalls,
                $currentText,
                $reasoningItems,
                $depth,
                $maxSteps,
                $timeout,
            );

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason($responseData)->value,
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
        ?int $timeout = null,
    ): Generator {
        foreach ($pendingToolCalls as &$pendingToolCall) {
            $this->attachLatestReasoning($pendingToolCall, $reasoningItems);
        }
        unset($pendingToolCall);

        $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);

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

            $body = array_merge($body, Arr::whereNotNull([
                'temperature' => $options?->temperature,
                'top_p' => $options?->topP,
                'max_output_tokens' => $options?->maxTokens,
            ]));

            $providerOptions = $options?->providerOptions(
                Lab::tryFrom($provider->driver()) ?? $provider->driver()
            );

            if (filled($providerOptions)) {
                $body = array_merge($body, $providerOptions);
            }

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post('responses', $body),
            );

            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $response->getBody(),
                $depth + 1,
                $maxSteps,
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

    protected function recordReasoningItem(array &$reasoningItems, array $item, int|string|null $outputIndex = null): void
    {
        $id = $item['id'] ?? null;

        if (! filled($id)) {
            return;
        }

        $reasoningItem = &$this->reasoningItem($reasoningItems, $id);

        if ($outputIndex !== null) {
            $reasoningItem['output_index'] = (int) $outputIndex;
        }

        if (isset($item['summary']) && is_array($item['summary']) && filled($item['summary'])) {
            $reasoningItem['summary'] = array_values($item['summary']);
        }
    }

    protected function appendReasoningSummaryDelta(array &$reasoningItems, array $event): void
    {
        $itemId = $event['item_id'] ?? null;
        $delta = (string) ($event['delta'] ?? '');

        if (! filled($itemId) || $delta === '') {
            return;
        }

        $summaryIndex = (int) ($event['summary_index'] ?? 0);
        $reasoningItem = &$this->reasoningItem($reasoningItems, $itemId);

        if (isset($event['output_index'])) {
            $reasoningItem['output_index'] = (int) $event['output_index'];
        }

        $summary = $reasoningItem['summary'][$summaryIndex] ?? ['type' => 'summary_text', 'text' => ''];
        $summary['text'] = ($summary['text'] ?? '').$delta;
        $summary['type'] = $summary['type'] ?? 'summary_text';
        $reasoningItem['summary'][$summaryIndex] = $summary;
        $reasoningItem['summary'] = array_values($reasoningItem['summary']);
    }

    protected function recordReasoningSummaryText(array &$reasoningItems, array $event): void
    {
        $itemId = $event['item_id'] ?? null;

        if (! filled($itemId)) {
            return;
        }

        $summaryIndex = (int) ($event['summary_index'] ?? 0);
        $reasoningItem = &$this->reasoningItem($reasoningItems, $itemId);

        if (isset($event['output_index'])) {
            $reasoningItem['output_index'] = (int) $event['output_index'];
        }

        $reasoningItem['summary'][$summaryIndex] = [
            'type' => 'summary_text',
            'text' => (string) ($event['text'] ?? ''),
        ];
        $reasoningItem['summary'] = array_values($reasoningItem['summary']);
    }

    protected function &reasoningItem(array &$reasoningItems, string $id): array
    {
        foreach ($reasoningItems as &$reasoningItem) {
            if (($reasoningItem['id'] ?? null) === $id) {
                return $reasoningItem;
            }
        }

        $reasoningItems[] = ['id' => $id, 'summary' => []];

        return $reasoningItems[array_key_last($reasoningItems)];
    }

    protected function upsertPendingToolCall(array &$toolCalls, int|string $index, array $item): void
    {
        $toolCalls[$index] ??= ['arguments' => ''];
        $toolCalls[$index]['output_index'] = (int) $index;

        foreach (['id', 'call_id', 'name'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null) {
                $toolCalls[$index][$key] = $item[$key];
            }
        }

        if (array_key_exists('arguments', $item)) {
            $toolCalls[$index]['arguments'] = (string) ($item['arguments'] ?? '');
        }
    }

    protected function syncPendingToolCallsFromOutput(array &$pendingToolCalls, array $output): void
    {
        $latestReasoning = null;

        foreach ($output as $index => $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = [
                    'id' => $item['id'] ?? null,
                    'summary' => $item['summary'] ?? [],
                ];

                continue;
            }

            if ($type !== 'function_call') {
                continue;
            }

            $toolCallIndex = $this->pendingToolCallIndex($pendingToolCalls, $item) ?? $index;

            $this->upsertPendingToolCall($pendingToolCalls, $toolCallIndex, $item);

            if ($latestReasoning !== null) {
                $this->attachReasoning($pendingToolCalls[$toolCallIndex], $latestReasoning);
            }
        }
    }

    protected function pendingToolCallIndex(array $pendingToolCalls, array $item): int|string|null
    {
        foreach ($pendingToolCalls as $index => $toolCall) {
            if (filled($item['id'] ?? null) && ($toolCall['id'] ?? null) === $item['id']) {
                return $index;
            }

            if (filled($item['call_id'] ?? null) && ($toolCall['call_id'] ?? null) === $item['call_id']) {
                return $index;
            }
        }

        return null;
    }

    protected function attachLatestReasoning(array &$toolCall, array $reasoningItems): void
    {
        if (! filled($reasoningItems)) {
            return;
        }

        $latestReasoning = $this->latestReasoningForToolCall($toolCall, $reasoningItems);

        if ($latestReasoning !== null) {
            $this->attachReasoning($toolCall, $latestReasoning);
        }
    }

    protected function latestReasoningForToolCall(array $toolCall, array $reasoningItems): ?array
    {
        $toolOutputIndex = $toolCall['output_index'] ?? null;
        $latestReasoning = null;

        if ($toolOutputIndex !== null) {
            foreach ($reasoningItems as $reasoningItem) {
                if (! isset($reasoningItem['output_index'])) {
                    continue;
                }

                if ((int) $reasoningItem['output_index'] <= (int) $toolOutputIndex) {
                    $latestReasoning = $reasoningItem;
                }
            }
        }

        return $latestReasoning ?? (end($reasoningItems) ?: null);
    }

    protected function attachReasoning(array &$toolCall, array $reasoning): void
    {
        if (isset($toolCall['reasoning_id']) || ! filled($reasoning['id'] ?? null)) {
            return;
        }

        $toolCall['reasoning_id'] = $reasoning['id'];
        $toolCall['reasoning_summary'] = $reasoning['summary'] ?? [];
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
