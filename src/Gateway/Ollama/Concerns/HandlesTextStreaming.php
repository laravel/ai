<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\Error;
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
     * Process an Ollama NDJSON streaming response and yield Laravel stream events.
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
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $usage = null;
        $lastData = [];

        foreach ($this->parseNdjsonStream($streamBody) as $data) {
            if (isset($data['error'])) {
                $error = $data['error'];
                $isStructured = is_array($error);

                yield (new Error(
                    $this->generateEventId(),
                    $isStructured ? ($error['code'] ?? 'unknown_error') : 'unknown_error',
                    $isStructured ? ($error['message'] ?? 'Unknown error') : (string) $error,
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
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

            $thinking = $data['message']['thinking'] ?? '';
            $content = $data['message']['content'] ?? '';

            if ($thinking !== '') {
                if ($reasoningId === null) {
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
                    $thinking,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($reasoningId !== null && ($content !== '' || ! empty($data['message']['tool_calls']))) {
                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = null;
            }

            if ($content !== '') {
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

            foreach ($data['message']['tool_calls'] ?? [] as $toolCall) {
                if (is_array($toolCall['function'] ?? null)) {
                    $toolCalls[] = $this->mapToolCall($toolCall);
                }
            }

            if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
                $usage = $this->extractUsage($data);
            }

            if ($data['done'] ?? false) {
                $lastData = $data;

                break;
            }
        }

        if ($reasoningId !== null) {
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

        foreach ($toolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: filled($toolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason($lastData),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $lastData['model'] ?? $model),
        );
    }

    /**
     * Parse an Ollama NDJSON stream, yielding each parsed JSON object.
     */
    protected function parseNdjsonStream($streamBody): Generator
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $buffer .= $streamBody->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);

                if ($data !== null) {
                    yield $data;
                }
            }
        }

        if (filled(trim($buffer))) {
            $data = json_decode(trim($buffer), true);

            if ($data !== null) {
                yield $data;
            }
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
