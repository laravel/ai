<?php

namespace Laravel\Ai\Gateway\Cohere\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

trait HandlesTextStreaming
{
    /**
     * Process a Cohere Chat API event stream.
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = null;
        $textStartEmitted = false;
        $currentText = '';
        $reasoning = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $usage = null;
        $finishReason = null;

        yield (new StreamStart(
            $this->generateEventId(),
            $provider->name(),
            $model,
            time(),
        ))->withInvocationId($invocationId);

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $delta = $data['delta'] ?? [];

            switch ($data['type'] ?? null) {
                case 'content-delta':
                    $thinking = (string) ($delta['message']['content']['thinking'] ?? '');
                    $content = (string) ($delta['message']['content']['text'] ?? '');

                    if ($thinking !== '') {
                        if ($reasoningId === null) {
                            $reasoningId = $this->generateEventId();

                            yield (new ReasoningStart(
                                $this->generateEventId(),
                                $reasoningId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $reasoning .= $thinking;

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $thinking,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    if ($content !== '') {
                        yield from $this->endReasoning($invocationId, $reasoningId);

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

                    break;

                case 'tool-call-start':
                    yield from $this->endReasoning($invocationId, $reasoningId);

                    $toolCall = $delta['message']['tool_calls'] ?? [];

                    $pendingToolCalls[$data['index'] ?? count($pendingToolCalls)] = [
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'arguments' => (string) ($toolCall['function']['arguments'] ?? ''),
                    ];

                    break;

                case 'tool-call-delta':
                    $index = $data['index'] ?? array_key_last($pendingToolCalls);

                    if (isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index]['arguments'] .= (string) ($delta['message']['tool_calls']['function']['arguments'] ?? '');
                    }

                    break;

                case 'message-end':
                    $finishReason = $delta['finish_reason'] ?? null;
                    $usage = $this->extractUsage($delta['usage'] ?? []);

                    break;
            }
        }

        yield from $this->endReasoning($invocationId, $reasoningId);

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        foreach ($pendingToolCalls as $pending) {
            $toolCall = new ToolCall(
                $pending['id'],
                $pending['name'],
                json_decode($pending['arguments'] ?: '{}', true) ?? [],
                $pending['id'] ?: null,
            );

            $toolCalls[] = $toolCall;

            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($finishReason),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
            reasoning: $reasoning,
        );
    }

    /**
     * Emit a reasoning end event if a reasoning block is open.
     *
     * @return Generator<int, StreamEvent>
     */
    protected function endReasoning(string $invocationId, ?string &$reasoningId): Generator
    {
        if ($reasoningId === null) {
            return;
        }

        yield (new ReasoningEnd(
            $this->generateEventId(),
            $reasoningId,
            time(),
        ))->withInvocationId($invocationId);

        $reasoningId = null;
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
