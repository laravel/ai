<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * Tracks the reasoning block of a Chat Completions response.
 *
 * Chat Completions has no reasoning block delimiters, so the block opens on the first
 * reasoning delta and closes as soon as answer text or tool calls begin, or when the
 * stream ends. Reasoning is captured so it can be replayed on the next request.
 */
class ChatCompletionReasoning
{
    /**
     * The provider content block key that reasoning is captured and replayed under.
     */
    public const CONTENT_BLOCK_KEY = 'reasoning_content';

    /**
     * The ID shared by the events of the currently open reasoning block.
     */
    protected string $reasoningId = '';

    /**
     * Indicates if a reasoning block is currently open.
     */
    protected bool $open = false;

    /**
     * The reasoning text accumulated so far.
     */
    protected string $text = '';

    /**
     * Indicates if this stream has sent reasoning as plain text.
     */
    protected bool $sentPlainText = false;

    /**
     * Emit the reasoning events for the given streaming delta.
     *
     * @param  array<string, mixed>  $delta
     * @return Generator<int, StreamEvent>
     */
    public function process(array $delta): Generator
    {
        $reasoning = static::plainTextFrom($delta);

        if ($reasoning !== '') {
            $this->sentPlainText = true;
        }

        // A stream that sends plain text keeps to it, so reasoning is never counted from both sources...
        if ($reasoning === '' && ! $this->sentPlainText) {
            $reasoning = static::detailsTextFrom($delta);
        }

        if ($reasoning !== '') {
            if (! $this->open) {
                $this->open = true;
                $this->reasoningId = $this->generateEventId();

                yield new ReasoningStart($this->generateEventId(), $this->reasoningId, time());
            }

            $this->text .= $reasoning;

            yield new ReasoningDelta($this->generateEventId(), $this->reasoningId, $reasoning, time());
        }

        // Answer text or tool calls mean the model has stopped thinking...
        if ($this->startsAnswer($delta)) {
            yield from $this->close();
        }
    }

    /**
     * Emit the reasoning end event if a block is still open.
     *
     * @return Generator<int, StreamEvent>
     */
    public function close(): Generator
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        yield new ReasoningEnd($this->generateEventId(), $this->reasoningId, time());

        $this->reasoningId = '';
    }

    /**
     * Get the provider content blocks holding the accumulated reasoning.
     *
     * @return array<string, string>
     */
    public function providerContentBlocks(): array
    {
        return static::providerContentBlocksFor($this->text);
    }

    /**
     * Get the provider content blocks holding the given reasoning text.
     *
     * @return array<string, string>
     */
    public static function providerContentBlocksFor(string $reasoning): array
    {
        return $reasoning === '' ? [] : [static::CONTENT_BLOCK_KEY => $reasoning];
    }

    /**
     * Get the reasoning to replay for the given assistant message provider content blocks.
     *
     * @param  array<array-key, mixed>  $providerContentBlocks
     */
    public static function replayableFrom(array $providerContentBlocks): ?string
    {
        $reasoning = $providerContentBlocks[static::CONTENT_BLOCK_KEY] ?? null;

        return is_string($reasoning) && $reasoning !== '' ? $reasoning : null;
    }

    /**
     * Extract the reasoning text from a Chat Completions message or streaming delta.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function textFrom(array $payload): string
    {
        $reasoning = static::plainTextFrom($payload);

        return $reasoning === '' ? static::detailsTextFrom($payload) : $reasoning;
    }

    /**
     * Extract the plain text reasoning from a Chat Completions message or streaming delta.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function plainTextFrom(array $payload): string
    {
        // OpenRouter sends `reasoning`, while DeepSeek, LiteLLM and vLLM send `reasoning_content`...
        foreach ([$payload['reasoning'] ?? null, $payload['reasoning_content'] ?? null] as $reasoning) {
            if (is_string($reasoning) && $reasoning !== '') {
                return $reasoning;
            }
        }

        return '';
    }

    /**
     * Extract the reasoning text carried by structured reasoning details.
     *
     * Encrypted details hold no readable text and are skipped.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function detailsTextFrom(array $payload): string
    {
        $details = $payload['reasoning_details'] ?? null;
        $text = '';

        foreach (is_array($details) ? $details : [] as $detail) {
            $part = is_array($detail) ? ($detail['text'] ?? $detail['summary'] ?? null) : null;

            if (is_string($part)) {
                $text .= $part;
            }
        }

        return $text;
    }

    /**
     * Determine if the given streaming delta begins the model's answer.
     *
     * @param  array<string, mixed>  $delta
     */
    protected function startsAnswer(array $delta): bool
    {
        $content = $delta['content'] ?? null;

        return (is_string($content) && $content !== '') || filled($delta['tool_calls'] ?? null);
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
