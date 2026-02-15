<?php

namespace Laravel\Ai\Responses\Concerns;

use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

trait CanStreamUsingDataProtocol
{
    /**
     * Create an HTTP response that represents the object using the Vercel AI SDK Data Protocol.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function toDataProtocolResponse()
    {
        $generator = function () {
            foreach ($this as $event) {
                try {
                    $mappedEvent = match ($event::class) {
                        StreamStart::class => new StreamStartEvent(
                            id: $event->id,
                            timestamp: $event->timestamp,
                            model: $event->model,
                            provider: $event->provider,
                            metadata: $event->metadata,
                        ),
                        TextStart::class => new TextStartEvent(
                            id: $event->id,
                            messageId: $event->messageId,
                            timestamp: $event->timestamp,
                        ),
                        TextDelta::class => new TextDeltaEvent(
                            id: $event->id,
                            messageId: $event->messageId,
                            delta: $event->delta,
                            timestamp: $event->timestamp,
                        ),
                        TextEnd::class => new TextCompleteEvent(
                            id: $event->id,
                            messageId: $event->messageId,
                            timestamp: $event->timestamp,
                        ),
                        ReasoningStart::class => new ThinkingStartEvent(
                            id: $event->id,
                            reasoningId: $event->reasoningId,
                            timestamp: $event->timestamp,
                        ),
                        ReasoningDelta::class => new ThinkingEvent(
                            id: $event->id,
                            reasoningId: $event->reasoningId,
                            delta: $event->delta,
                            timestamp: $event->timestamp,
                        ),
                        ReasoningEnd::class => new ThinkingCompleteEvent(
                            id: $event->id,
                            reasoningId: $event->reasoningId,
                            timestamp: $event->timestamp,
                        ),
                        ToolCall::class => new ToolCallEvent(
                            id: $event->id,
                            timestamp: $event->timestamp,
                            toolCall: new PrismToolCall(
                                id: $event->toolCall->id,
                                name: $event->toolCall->name,
                                arguments: $event->toolCall->arguments,
                                reasoningId: $event->toolCall->reasoningId,
                            ),
                            messageId: '', // Message ID is not available in Laravel\Ai ToolCall event
                        ),
                        ToolResult::class => new ToolResultEvent(
                            id: $event->id,
                            timestamp: $event->timestamp,
                            toolResult: new PrismToolResult(
                                toolCallId: $event->toolResult->id,
                                toolName: $event->toolResult->name,
                                args: $event->toolResult->arguments,
                                result: $event->toolResult->result,
                                toolCallResultId: $event->toolResult->resultId
                            ),
                            messageId: '', // Message ID is not available in Laravel\Ai ToolResult event
                        ),
                        StreamEnd::class => new StreamEndEvent(
                            id: $event->id,
                            timestamp: $event->timestamp,
                            finishReason: FinishReason::Stop, // Defaulting to Stop as specific reason mapping might be needed
                            usage: new Usage(
                                promptTokens: $event->usage->promptTokens ?? 0,
                                completionTokens: $event->usage->completionTokens ?? 0,
                            )
                        ),
                        Error::class => new ErrorEvent(
                            id: $event->id,
                            timestamp: $event->timestamp,
                            errorType: 'Error',
                            message: $event->message,
                            recoverable: false,
                        ),
                        default => null,
                    };

                    if ($mappedEvent) {
                        yield $mappedEvent;
                    }
                } catch (Throwable $e) {
                    // Fail silently for events we can't map
                    continue;
                }
            }
        };

        return (new DataProtocolAdapter)($generator());
    }
}
