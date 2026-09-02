<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
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

trait HandlesTextStreaming
{
    /**
     * Process a Gemini streaming response and yield Laravel stream events.
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
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $inReasoning = false;
        $currentText = '';
        $pendingToolCalls = [];
        $modelParts = [];
        $usage = null;
        $data = [];
        $citationData = [];

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

            // Citation metadata may arrive on any chunk, not necessarily the last...
            if (isset($candidate['groundingMetadata']) || isset($candidate['citationMetadata'])) {
                $citationData = $data;
            }

            foreach ($parts as $part) {
                if (isset($part['text']) && $this->isThinkingPart($part)) {
                    $modelParts[] = $part;
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
                    $modelParts[] = $part;

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

                if (isset($part['functionCall'])) {
                    $pendingToolCalls[] = $part['functionCall'];
                    $modelParts[] = $part;

                    continue;
                }

                if (isset($part['executableCode']) || isset($part['codeExecutionResult'])) {
                    $modelParts[] = $part;

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        '',
                        'code_execution',
                        $part,
                        isset($part['executableCode']) ? 'code_generated' : 'result_received',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
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

        // Map and emit any pending tool calls...
        $toolCalls = [];

        if (filled($pendingToolCalls)) {
            $toolCalls = $this->mapToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        // Emit citations from the last chunk that carried citation metadata...
        foreach ($this->extractCitations($citationData) as $citation) {
            yield (new CitationEvent(
                $this->generateEventId(),
                $messageId,
                $citation,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($data, $pendingToolCalls),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $model),
            providerContentBlocks: $this->sanitizeRequestParts($this->excludeThinkingParts($modelParts)),
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
