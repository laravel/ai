<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

trait HandlesTextGeneration
{
    /** @return Generator<int, StreamEvent, mixed, StepResponse|null> */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $responseId = '';
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
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
                $currentText .= $textDelta;

                if ($textDelta !== '') {
                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

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
                    'encrypted_content' => $data['item']['encrypted_content'] ?? null,
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

            if (str_starts_with((string) $type, 'response.') && str_contains((string) $type, '_call.')) {
                $parts = explode('.', (string) $type, 3);

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
                    $toolCall['reasoning_encrypted_content'] = $latestReasoning['encrypted_content'] ?? null;
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

                        $toolCall = new ToolCall(
                            $call['id'],
                            $call['name'],
                            json_decode($call['arguments'] ?? '{}', true) ?? [],
                            $call['call_id'] ?? null,
                            $call['reasoning_id'] ?? null,
                            $call['reasoning_summary'] ?? null,
                            $call['reasoning_encrypted_content'] ?? null,
                        );

                        $toolCalls[] = $toolCall;

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            $toolCall,
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
                $responseData = $response;
                $responseId = $response['id'] ?? $responseId;

                $usage = $this->extractUsage($response);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($responseData),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $responseData['model'] ?? $model),
            continuationToken: $responseId,
        );
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
