<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
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
        $steps = [];
        $partialArguments = [];
        $final = [];

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $event = $data['event_type'] ?? '';

            if ($event === 'error' || isset($data['error'])) {
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
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($event === 'interaction.completed') {
                $final = $data['interaction'] ?? $data;

                continue;
            }

            $index = $data['index'] ?? 0;

            if (isset($data['step']) && is_array($data['step'])) {
                $steps[$index] = array_merge($steps[$index] ?? [], $data['step']);
            }

            $delta = $data['delta'] ?? null;

            if (is_array($delta)) {
                $deltaType = $delta['type'] ?? '';

                // Gemini streams the rest of a step's payload as delta keys: thought signatures,
                // provider tool arguments and their results all arrive this way...
                foreach (Arr::except($delta, ['type', 'text', 'content']) as $key => $value) {
                    $steps[$index][$key] = $value;
                }

                if ($deltaType === 'thought' || $deltaType === 'thought_summary') {
                    // A thought summary nests its text one level deeper than a plain text delta...
                    $reasoningDelta = (string) ($delta['content']['text'] ?? $delta['text'] ?? '');

                    $this->appendStepText($steps, $index, 'thought', $reasoningDelta, 'summary');

                    if ($reasoningDelta !== '') {
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
                            $reasoningDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'text') {
                    $textDelta = (string) ($delta['text'] ?? '');

                    $this->appendStepText($steps, $index, 'model_output', $textDelta);

                    if ($inReasoning) {
                        $inReasoning = false;

                        yield (new ReasoningEnd(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);

                        $reasoningId = '';
                    }

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
                } elseif ($deltaType === 'arguments_delta') {
                    // Gemini splits function call arguments across deltas as partial JSON strings...
                    $partialArguments[$index] = ($partialArguments[$index] ?? '').($delta['arguments'] ?? '');

                    $steps[$index]['type'] = 'function_call';
                    $steps[$index]['arguments'] = $partialArguments[$index];
                }
            }

            if ($event === 'step.stop' && $this->isProviderToolStep($steps[$index] ?? [])) {
                yield (new ProviderToolEvent(
                    $this->generateEventId(),
                    (string) ($steps[$index]['id'] ?? ''),
                    (string) preg_replace('/_(call|result)$/', '', $steps[$index]['type']),
                    $steps[$index],
                    str_ends_with($steps[$index]['type'], '_result') ? 'result_received' : 'completed',
                    time(),
                    provider: $provider->name(),
                ))->withInvocationId($invocationId);
            }
        }

        if ($inReasoning) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        // The completed event carries the authoritative steps when it lists them at all...
        $steps = filled($final['steps'] ?? null) ? $final['steps'] : array_values($steps);

        $functionCallSteps = $this->extractFunctionCallSteps($steps);
        $toolCalls = $this->mapToolCalls($functionCallSteps);

        foreach ($toolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        foreach ($this->extractCitations($steps) as $citation) {
            yield (new CitationEvent(
                $this->generateEventId(),
                $messageId,
                $citation,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText !== '' ? $currentText : $this->extractText($steps),
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($final, $functionCallSteps),
            usage: filled($final) ? $this->extractUsage($final) : new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
            replayBlocks: $this->replayableSteps($steps),
            reasoning: $this->extractReasoning($steps),
            providerToolCalls: $this->extractProviderToolCalls($steps),
        );
    }

    /**
     * Append streamed text to the step being accumulated at the given index.
     */
    protected function appendStepText(array &$steps, int|string $index, string $type, string $text, string $key = 'content'): void
    {
        $steps[$index]['type'] ??= $type;
        $steps[$index][$key][0]['type'] ??= 'text';
        $steps[$index][$key][0]['text'] = ($steps[$index][$key][0]['text'] ?? '').$text;
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
