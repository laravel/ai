<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Generator;
use Illuminate\Support\Arr;
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
 * stream ends. Both the readable text and the structured details are captured, since
 * replaying reasoning needs the details verbatim while rendering it needs the text.
 */
class ChatCompletionReasoning
{
    /**
     * The provider content block key holding the readable reasoning text.
     */
    public const CONTENT_BLOCK_KEY = 'reasoning_content';

    /**
     * The provider content block key holding the structured reasoning details.
     */
    public const DETAILS_BLOCK_KEY = 'reasoning_details';

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
     * The structured reasoning details accumulated so far, keyed by block identity.
     *
     * @var array<array-key, array<string, mixed>>
     */
    protected array $details = [];

    /**
     * Where this stream reads its reasoning text from, either "plain" or "details".
     */
    protected ?string $source = null;

    public function __construct(protected string $invocationId) {}

    /**
     * Emit the reasoning events for the given streaming delta.
     *
     * @param  array<string, mixed>  $delta
     * @return Generator<int, StreamEvent>
     */
    public function process(array $delta): Generator
    {
        $plain = static::plainTextFrom($delta);

        // Details are merged even when the text is read elsewhere, since only they can be replayed verbatim...
        $details = $this->mergeDetails($delta);

        // The first reasoning-bearing chunk picks the source, so mirrored fields are never counted twice...
        $this->source ??= match (true) {
            $plain !== '' => 'plain',
            $details !== '' => 'details',
            default => null,
        };

        $reasoning = match ($this->source) {
            'plain' => $plain,
            'details' => $details,
            default => '',
        };

        if ($reasoning !== '') {
            if (! $this->open) {
                $this->open = true;
                $this->reasoningId = $this->generateEventId();

                yield $this->event(new ReasoningStart($this->generateEventId(), $this->reasoningId, time()));
            }

            $this->text .= $reasoning;

            yield $this->event(new ReasoningDelta($this->generateEventId(), $this->reasoningId, $reasoning, time()));
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

        yield $this->event(new ReasoningEnd($this->generateEventId(), $this->reasoningId, time()));

        $this->reasoningId = '';
    }

    /**
     * Get the provider content blocks capturing the reasoning seen so far.
     *
     * @return array<string, mixed>
     */
    public function providerContentBlocks(): array
    {
        return static::providerContentBlocksFor($this->text, array_values($this->details));
    }

    /**
     * Get the provider content blocks capturing the reasoning of the given Chat Completions message.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function providerContentBlocksIn(array $message): array
    {
        return static::providerContentBlocksFor(static::textFrom($message), static::detailsFrom($message));
    }

    /**
     * Get the provider content blocks holding the given reasoning text and details.
     *
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    public static function providerContentBlocksFor(string $reasoning, array $details = []): array
    {
        return array_filter([
            static::CONTENT_BLOCK_KEY => $reasoning,
            static::DETAILS_BLOCK_KEY => $details,
        ]);
    }

    /**
     * Get the readable reasoning text to replay for the given assistant message provider content blocks.
     *
     * @param  array<array-key, mixed>  $providerContentBlocks
     */
    public static function replayableFrom(array $providerContentBlocks): ?string
    {
        $reasoning = $providerContentBlocks[static::CONTENT_BLOCK_KEY] ?? null;

        return is_string($reasoning) && $reasoning !== '' ? $reasoning : null;
    }

    /**
     * Get the structured reasoning details to replay verbatim, which carry the signatures and encrypted payloads that the readable text loses.
     *
     * @param  array<array-key, mixed>  $providerContentBlocks
     * @return array<int, array<string, mixed>>|null
     */
    public static function replayableDetailsFrom(array $providerContentBlocks): ?array
    {
        $details = Arr::where(
            Arr::wrap($providerContentBlocks[static::DETAILS_BLOCK_KEY] ?? null),
            fn (mixed $detail): bool => is_array($detail) && ! static::isUnsignedAnthropicText($detail),
        );

        return $details === [] ? null : array_values($details);
    }

    /**
     * Determine if the given detail is an Anthropic reasoning text block that lost the signature Anthropic requires back.
     *
     * @param  array<string, mixed>  $detail
     */
    protected static function isUnsignedAnthropicText(array $detail): bool
    {
        return ($detail['type'] ?? null) === 'reasoning.text'
            && str_starts_with((string) ($detail['format'] ?? ''), 'anthropic')
            && blank($detail['signature'] ?? null);
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
     * Extract the structured reasoning details from a Chat Completions message or streaming delta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function detailsFrom(array $payload): array
    {
        $details = $payload[static::DETAILS_BLOCK_KEY] ?? null;

        return array_values(array_filter(is_array($details) ? $details : [], is_array(...)));
    }

    /**
     * Merge the given delta's reasoning details into the captured blocks, returning the text that newly arrived.
     *
     * @param  array<string, mixed>  $delta
     */
    protected function mergeDetails(array $delta): string
    {
        $arrived = '';

        foreach (static::detailsFrom($delta) as $position => $detail) {
            // Reasoning details of different types may share an id or index...
            $key = ($detail['type'] ?? '').'#'.($detail['id'] ?? $detail['index'] ?? $position);

            if (! isset($this->details[$key])) {
                $this->details[$key] = $detail;

                $arrived .= static::readableTextFrom($detail);

                continue;
            }

            $arrived .= $this->mergeDetail($key, $detail);
        }

        return $arrived;
    }

    /**
     * Merge a repeated detail into the block already captured under the given key, returning the text that newly arrived.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function mergeDetail(string $key, array $detail): string
    {
        $captured = static::readableTextFrom($this->details[$key]);
        $incoming = static::readableTextFrom($detail);

        // Upstreams either stream detail fragments or resend the block accumulated so far...
        $arrived = str_starts_with($incoming, $captured) ? substr($incoming, strlen($captured)) : $incoming;

        $this->details[$key] = [...$this->details[$key], ...$detail];

        foreach (['text', 'summary'] as $field) {
            if (array_key_exists($field, $this->details[$key])) {
                $this->details[$key][$field] = $captured.$arrived;

                break;
            }
        }

        return $arrived;
    }

    /**
     * Extract the plain text reasoning from a Chat Completions message or streaming delta.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function plainTextFrom(array $payload): string
    {
        // OpenRouter sends `reasoning`, while DeepSeek, LiteLLM and vLLM send `reasoning_content`...
        foreach ([$payload['reasoning'] ?? null, $payload[static::CONTENT_BLOCK_KEY] ?? null] as $reasoning) {
            if (is_string($reasoning) && $reasoning !== '') {
                return $reasoning;
            }
        }

        return '';
    }

    /**
     * Extract the reasoning text carried by structured reasoning details.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function detailsTextFrom(array $payload): string
    {
        $text = '';

        foreach (static::detailsFrom($payload) as $detail) {
            $text .= static::readableTextFrom($detail);
        }

        return $text;
    }

    /**
     * Get the readable text of a single reasoning detail, which is empty for encrypted blocks.
     *
     * @param  array<string, mixed>  $detail
     */
    protected static function readableTextFrom(array $detail): string
    {
        $text = $detail['text'] ?? $detail['summary'] ?? null;

        return is_string($text) ? $text : '';
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
     * Tag the given event with the invocation it belongs to.
     */
    protected function event(StreamEvent $event): StreamEvent
    {
        return $event->withInvocationId($this->invocationId);
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
