<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

trait HandlesTextStreaming
{
    /**
     * Process a Chat Completions streaming response for a single turn and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;
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
                if (isset($data['usage'])) {
                    $usage = new Usage(
                        $data['usage']['prompt_tokens'] ?? 0,
                        $data['usage']['completion_tokens'] ?? 0,
                    );
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
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
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

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

            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = new Usage(
                    $data['usage']['prompt_tokens'] ?? 0,
                    $data['usage']['completion_tokens'] ?? 0,
                );
            }
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($mappedToolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason(['finish_reason' => $finishReason ?? ''])->value,
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['name'] ?? '',
            json_decode($toolCall['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
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
