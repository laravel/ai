<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
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
     * Process a Gemini streaming response for a single turn and yield Laravel stream events.
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
        $inReasoning = false;
        $pendingToolCalls = [];
        $usage = null;
        $data = [];

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

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['modelVersion'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $candidate = $data['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];

            foreach ($parts as $part) {
                if (isset($part['text']) && $this->isThinkingPart($part)) {
                    $delta = $part['text'];

                    if ($delta !== '') {
                        if (! $inReasoning) {
                            $inReasoning = true;
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

                if (isset($part['text'])) {
                    if ($inReasoning) {
                        $inReasoning = false;

                        yield (new ReasoningEnd(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);

                        $reasoningId = '';
                    }

                    $textDelta = $part['text'];

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

                if (isset($part['functionCall'])) {
                    $pendingToolCalls[] = $part['functionCall'];

                    continue;
                }
            }

            if (isset($data['usageMetadata'])) {
                $usage = $this->extractUsage($data);
            }
        }

        // End reasoning if still open...
        if ($inReasoning) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        // End text if it was started...
        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        // Handle pending tool calls...
        if (filled($pendingToolCalls)) {
            $mappedToolCalls = $this->mapToolCalls($pendingToolCalls);

            foreach ($mappedToolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield (new StreamEnd(
                $this->generateEventId(),
                'tool_calls',
                $usage ?? new Usage(0, 0),
                time(),
            ))->withInvocationId($invocationId);

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            $this->extractFinishReason($data, $pendingToolCalls)->value,
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
