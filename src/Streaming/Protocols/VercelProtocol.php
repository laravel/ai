<?php

namespace Laravel\Ai\Streaming\Protocols;

use Generator;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * The Vercel AI SDK data stream protocol.
 *
 * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelProtocol extends StreamProtocol
{
    protected ?string $invocationId = null;

    public function __construct(protected ?string $messageId = null)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $this->started = false;
        $this->errored = false;
        $this->invocationId = $response->invocationId;

        $toolCalls = [];
        $lastStreamEnd = null;
        $usage = null;

        foreach ($response as $event) {
            // Send one stream start event, wrapping each subsequent provider step in step parts...
            if ($event instanceof StreamStart && $this->started) {
                yield ['type' => 'finish-step'];
                yield ['type' => 'start-step'];

                continue;
            }

            // Track tool calls initiated within this stream.
            if ($event instanceof ToolCall) {
                $toolCalls[$event->toolCall->id] = true;
            }

            if ($event instanceof Error) {
                $this->errored = true;
            }

            // A result without a local call is valid only when continuing the client message that contains the call...
            if ($event instanceof ToolResult
                && ! isset($toolCalls[$event->toolResult->id])
                && $this->messageId === null) {
                continue;
            }

            if ($event instanceof ToolApprovalRequest) {
                foreach ($event->pendingApprovals as $pendingApproval) {
                    yield from $this->part([
                        'type' => 'tool-approval-request',
                        'toolCallId' => $pendingApproval->id,
                        'approvalId' => $pendingApproval->id,
                        'reason' => $pendingApproval->reason,
                    ]);
                }

                continue;
            }

            // Save the last stream end event until the very end, combining usage across steps...
            if ($event instanceof StreamEnd) {
                $lastStreamEnd = $event;
                $usage = ($usage ?? new Usage)->add($event->usage);

                continue;
            }

            if (empty($part = $this->map($event))) {
                continue;
            }

            yield from $this->part($part);
        }

        if ($this->started && ! $this->errored) {
            yield ['type' => 'finish-step'];

            if ($lastStreamEnd instanceof StreamEnd) {
                yield $this->finishPart($lastStreamEnd->reason, $usage ?? new Usage);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->part(['type' => 'error', 'errorText' => 'An error occurred.']);
    }

    /**
     * {@inheritdoc}
     */
    protected function headers(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function terminator(): ?string
    {
        return "data: [DONE]\n\n";
    }

    /**
     * Get the given protocol part, preceded by a start part when one has not been sent yet.
     *
     * @param  array<string, mixed>  $part
     */
    protected function part(array $part): Generator
    {
        if ($part['type'] === 'start') {
            $this->started = true;

            $part['messageId'] = $this->messageId ?? $part['messageId'];

            yield $part;
            yield ['type' => 'start-step'];

            return;
        }

        if (! $this->started) {
            yield from $this->part(['type' => 'start', 'messageId' => $this->invocationId]);
        }

        yield $part;
    }

    /**
     * Get the protocol part that represents the given event.
     *
     * @return array<string, mixed>|null
     */
    protected function map(StreamEvent $event): ?array
    {
        return match (true) {
            $event instanceof StreamStart => [
                'type' => 'start',
                'messageId' => $event->id,
            ],
            $event instanceof TextStart => [
                'type' => 'text-start',
                'id' => $event->messageId,
            ],
            $event instanceof TextDelta => [
                'type' => 'text-delta',
                'id' => $event->messageId,
                'delta' => $event->delta,
            ],
            $event instanceof TextEnd => [
                'type' => 'text-end',
                'id' => $event->messageId,
            ],
            $event instanceof ReasoningStart => [
                'type' => 'reasoning-start',
                'id' => $event->reasoningId,
            ],
            $event instanceof ReasoningDelta => [
                'type' => 'reasoning-delta',
                'id' => $event->reasoningId,
                'delta' => $event->delta,
            ],
            $event instanceof ReasoningEnd => [
                'type' => 'reasoning-end',
                'id' => $event->reasoningId,
            ],
            $event instanceof ToolCall => [
                'type' => 'tool-input-available',
                'toolCallId' => $event->toolCall->id,
                'toolName' => $event->toolCall->name,
                'input' => $event->toolCall->arguments,
            ],
            $event instanceof ToolResult => $this->toolResultPart($event),
            $event instanceof Citation => $this->citationPart($event),
            $event instanceof Error => [
                'type' => 'error',
                'errorText' => $event->message,
            ],
            default => null,
        };
    }

    /**
     * Get the protocol part that represents the given tool result event.
     *
     * @return array<string, mixed>
     */
    protected function toolResultPart(ToolResult $event): array
    {
        if ($event->denied) {
            return [
                'type' => 'tool-output-denied',
                'toolCallId' => $event->toolResult->id,
            ];
        }

        if (! $event->successful) {
            return [
                'type' => 'tool-output-error',
                'toolCallId' => $event->toolResult->id,
                'errorText' => $event->error ?? 'The tool call failed.',
            ];
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $event->toolResult->id,
            'output' => $event->toolResult->result,
        ];
    }

    /**
     * Get the protocol part that represents the given citation event.
     *
     * @return array<string, mixed>|null
     */
    protected function citationPart(Citation $event): ?array
    {
        return match (true) {
            $event->citation instanceof UrlCitation => array_filter([
                'type' => 'source-url',
                'sourceId' => $event->citation->url,
                'url' => $event->citation->url,
                'title' => $event->citation->title,
            ], fn ($value) => $value !== null),
            default => null,
        };
    }

    /**
     * Get the protocol part that finishes the stream.
     *
     * @return array<string, mixed>
     */
    protected function finishPart(string $reason, Usage $usage): array
    {
        return [
            'type' => 'finish',
            'finishReason' => match ($reason) {
                'stop' => 'stop',
                'tool_calls' => 'tool-calls',
                'length' => 'length',
                'content_filter' => 'content-filter',
                'error' => 'error',
                'unknown' => 'unknown',
                default => 'other',
            },
            'messageMetadata' => [
                'usage' => [
                    'inputTokens' => $usage->promptTokens,
                    'outputTokens' => $usage->completionTokens,
                    'totalTokens' => $usage->promptTokens + $usage->completionTokens,
                    'reasoningTokens' => $usage->reasoningTokens,
                    'cachedInputTokens' => $usage->cacheReadInputTokens,
                ],
            ],
        ];
    }
}
