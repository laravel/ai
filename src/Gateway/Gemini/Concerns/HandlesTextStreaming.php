<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolResult;
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
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

trait HandlesTextStreaming
{
    /**
     * Process a Gemini streaming response and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        $streamBody,
        array $contents = [],
        ?string $instructions = null,
        int $depth = 0,
        ?int $maxSteps = null,
        ?int $timeout = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

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
            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $pendingToolCalls,
                $contents,
                $instructions,
                $modelParts,
                $depth,
                $maxSteps,
                $timeout,
            );

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
        array $contents,
        ?string $instructions,
        array $modelParts,
        int $depth,
        ?int $maxSteps,
        ?int $timeout = null,
    ): Generator {
        $mappedToolCalls = $this->mapToolCalls($pendingToolCalls);

        // Emit tool call events...
        foreach ($mappedToolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

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
            $contents[] = ['role' => 'model', 'parts' => $this->sanitizeRequestParts($this->excludeThinkingParts($modelParts))];
            $contents[] = ['role' => 'user', 'parts' => $this->buildFunctionResponseParts($toolResults)];

            $body = $this->rebuildContinuationBody($contents, $instructions, $tools, $schema, $options, $provider);

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $timeout)
                    ->withOptions(['stream' => true])
                    ->post("models/{$model}:streamGenerateContent?alt=sse", $body),
            );

            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $response->getBody(),
                $contents,
                $instructions,
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
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
