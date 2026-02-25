<?php

namespace Laravel\Ai\Gateway\Prism;

use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent as LaravelProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent as LaravelStreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Prism\Prism\Enums\StreamEventType as PrismStreamEventType;
use Prism\Prism\Streaming\Events\ProviderToolEvent as ProviderToolStreamEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

class PrismStreamEvent
{
    /**
     * Convert a Prism stream event to a Laravel AI SDK stream event.
     */
    public static function toLaravelStreamEvent(string $invocationId, StreamEvent $event, string $provider, string $model): ?LaravelStreamEvent
    {
        if (isset($event->id)) {
            $id = strtolower($event->id);
        }

        return tap(match ($event->type()) {
            PrismStreamEventType::StreamStart => new StreamStart($id ?? $event->id, $provider, $model, $event->timestamp, $event->metadata),
            PrismStreamEventType::TextStart => new TextStart($id ?? $event->id, strtolower($event->messageId), $event->timestamp),
            PrismStreamEventType::TextDelta => new TextDelta($id ?? $event->id, strtolower($event->messageId), $event->delta, $event->timestamp),
            PrismStreamEventType::TextComplete => new TextEnd($id ?? $event->id, strtolower($event->messageId), $event->timestamp),
            PrismStreamEventType::ThinkingStart => new ReasoningStart($id ?? $event->id, strtolower($event->reasoningId), $event->timestamp),
            PrismStreamEventType::ThinkingDelta => new ReasoningDelta($id ?? $event->id, strtolower($event->reasoningId), $event->delta, $event->timestamp, $event->summary),
            PrismStreamEventType::ThinkingComplete => new ReasoningEnd($id ?? $event->id, strtolower($event->reasoningId), $event->timestamp, $event->summary ?? null),
            PrismStreamEventType::ToolCall => static::toToolCallEvent($event),
            PrismStreamEventType::ToolResult => static::toToolResultEvent($event),
            PrismStreamEventType::ProviderToolEvent => static::toProviderToolEvent($event),
            // PrismStreamEventType::Citation => new Citation($id ?? $event->id, $event->messageId, PrismCitations::toLaravelCitation($event->citation), $event->timestamp),
            PrismStreamEventType::StreamEnd => static::toStreamEndEvent($event),
            PrismStreamEventType::Error => new Error($event->id, $event->type, $event->message, $event->recoverable, $event->timestamp, $event->metadata),
            default => null
        }, function ($event) use ($invocationId) {
            $event?->withInvocationId($invocationId);
        });
    }

    /**
     * Convert the given event to a tool call event.
     */
    protected static function toToolCallEvent(ToolCallEvent $event): ToolCall
    {
        return new ToolCall(
            strtolower($event->id),
            PrismTool::toLaravelToolCall($event->toolCall),
            $event->timestamp,
        );
    }

    /**
     * Convert the given event to a tool result event.
     */
    protected static function toToolResultEvent(ToolResultEvent $event): ToolResult
    {
        return new ToolResult(
            strtolower($event->id),
            PrismTool::toLaravelToolResult($event->toolResult),
            $event->success,
            $event->error,
            $event->timestamp
        );
    }

    /**
     * Convert the given event to a tool result event.
     */
    protected static function toProviderToolEvent(ProviderToolStreamEvent $event): LaravelProviderToolEvent
    {
        return new LaravelProviderToolEvent(
            strtolower($event->id),
            $event->itemId,
            $event->toolType,
            $event->data,
            $event->status,
            $event->timestamp,
        );
    }

    /**
     * Convert the given event to a stream end event.
     */
    protected static function toStreamEndEvent(StreamEndEvent $event): StreamEnd
    {
        return new StreamEnd(
            strtolower($event->id),
            $event->finishReason->value,
            PrismUsage::toLaravelUsage($event->usage),
            $event->timestamp,
        );
    }
}
