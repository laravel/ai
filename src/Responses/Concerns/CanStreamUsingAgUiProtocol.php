<?php

namespace Laravel\Ai\Responses\Concerns;

use Generator;
use Laravel\Ai\Streaming\Events\Citation;
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
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait CanStreamUsingAgUiProtocol
{
    /**
     * Create an HTTP response using the Agent User Interaction Protocol.
     */
    protected function toAgUiProtocolResponse(): Response
    {
        $state = new class
        {
            public bool $runFinished = false;

            public ?string $activeTextMessageId = null;

            public ?string $activeTextSourceId = null;

            public ?string $activeReasoningMessageId = null;

            public ?string $activeReasoningSourceId = null;

            public ?string $lastMessageId = null;

            /** @var array<string, int> */
            public array $messageIdUses = [];

            /** @var array<string, true> */
            public array $toolCallIds = [];
        };

        return response()->stream(function () use ($state) {
            $threadId = $this->agUiThreadId ?? $this->conversationId ?? $this->invocationId;
            $runId = $this->agUiRunId ?? $this->invocationId;

            yield $this->toAgUiProtocolFrame([
                'type' => 'RUN_STARTED',
                'threadId' => $threadId,
                'runId' => $runId,
            ]);

            try {
                foreach ($this as $event) {
                    if ($state->runFinished) {
                        continue;
                    }

                    yield from $this->toAgUiProtocolEvents($state, $event, $threadId, $runId);
                }
            } catch (Throwable $e) {
                // Report the exception to the handler by hand: the stream is already open, so it is closed with a terminal frame instead of being re-thrown out of the send callback.
                report($e);

                if (! $state->runFinished) {
                    yield from $this->closeAgUiMessages($state);

                    $state->runFinished = true;

                    yield $this->toAgUiProtocolFrame([
                        'type' => 'RUN_ERROR',
                        'message' => $e->getMessage(),
                        'code' => 'error',
                    ]);
                }
            }

            if (! $state->runFinished) {
                yield from $this->closeAgUiMessages($state);
                yield $this->finishAgUiRun($state, $threadId, $runId);
            }
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
        ]);
    }

    /**
     * Translate a Laravel AI stream event into one or more AG-UI events.
     */
    protected function toAgUiProtocolEvents(object $state, StreamEvent $event, string $threadId, string $runId): Generator
    {
        if ($event instanceof StreamStart) {
            return;
        }

        if ($event instanceof TextStart) {
            // Defer TEXT_MESSAGE_START to the first non-empty delta so a content-less text block emits no empty message.
            return;
        }

        if ($event instanceof TextDelta) {
            if ($event->delta === '') {
                return;
            }

            yield from $this->startAgUiTextMessage($state, $event->messageId);
            yield $this->toAgUiProtocolFrame([
                'type' => 'TEXT_MESSAGE_CONTENT',
                'messageId' => $state->activeTextMessageId,
                'delta' => $event->delta,
            ]);

            return;
        }

        if ($event instanceof TextEnd) {
            if ($state->activeTextSourceId === $event->messageId) {
                yield from $this->endAgUiTextMessage($state);
            }

            return;
        }

        if ($event instanceof ReasoningStart) {
            yield from $this->startAgUiReasoningMessage($state, $event->reasoningId);

            return;
        }

        if ($event instanceof ReasoningDelta) {
            if ($event->delta === '') {
                return;
            }

            yield from $this->startAgUiReasoningMessage($state, $event->reasoningId);
            yield $this->toAgUiProtocolFrame([
                'type' => 'REASONING_MESSAGE_CONTENT',
                'messageId' => $state->activeReasoningMessageId,
                'delta' => $event->delta,
            ]);

            return;
        }

        if ($event instanceof ReasoningEnd) {
            if ($state->activeReasoningSourceId === $event->reasoningId) {
                yield from $this->endAgUiReasoningMessage($state);
            }

            return;
        }

        if ($event instanceof ToolCall) {
            yield from $this->closeAgUiMessages($state);
            yield from $this->startAgUiToolCall($state, $event->toolCall->id, $event->toolCall->name, $event->toolCall->arguments);

            return;
        }

        if ($event instanceof ToolResult) {
            yield from $this->closeAgUiMessages($state);

            // On an approval-resume the tool call was introduced in a prior run, so synthesize its start/end here to keep the result from being orphaned.
            if (! isset($state->toolCallIds[$event->toolResult->id])) {
                yield from $this->startAgUiToolCall($state, $event->toolResult->id, $event->toolResult->name, $event->toolResult->arguments);
            }

            yield $this->toAgUiProtocolFrame([
                'type' => 'TOOL_CALL_RESULT',
                'messageId' => $event->toolResult->resultId ?? $event->id,
                'toolCallId' => $event->toolResult->id,
                'content' => $this->encodeAgUiContent($event),
                'role' => 'tool',
            ]);

            return;
        }

        if ($event instanceof ToolApprovalRequest) {
            yield from $this->closeAgUiMessages($state);

            $interrupts = $event->pendingApprovals->values()->map(fn ($approval): array => [
                'id' => $approval->id,
                'reason' => 'tool_call',
                'message' => $approval->reason ?? "Approve the {$approval->tool} tool call?",
                'toolCallId' => $approval->id,
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'approved' => ['type' => 'boolean'],
                        'editedArgs' => ['type' => 'object'],
                    ],
                    'required' => ['approved'],
                ],
            ])->all();

            $state->runFinished = true;

            yield $this->toAgUiProtocolFrame([
                'type' => 'RUN_FINISHED',
                'threadId' => $threadId,
                'runId' => $runId,
                'outcome' => [
                    'type' => 'interrupt',
                    'interrupts' => $interrupts,
                ],
            ]);

            return;
        }

        if ($event instanceof Error) {
            if ($event->recoverable) {
                yield $this->toAgUiProtocolFrame([
                    'type' => 'CUSTOM',
                    'name' => 'error',
                    'value' => $event->toArray(),
                ]);

                return;
            }

            yield from $this->closeAgUiMessages($state);

            $state->runFinished = true;

            yield $this->toAgUiProtocolFrame([
                'type' => 'RUN_ERROR',
                'message' => $event->message,
                'code' => $event->type,
            ]);

            return;
        }

        if ($event instanceof Citation) {
            yield $this->toAgUiProtocolFrame([
                'type' => 'CUSTOM',
                'name' => 'citation',
                'value' => $event->toArray(),
            ]);

            return;
        }

        if ($event instanceof ProviderToolEvent) {
            yield $this->toAgUiProtocolFrame([
                'type' => 'CUSTOM',
                'name' => $event->type,
                'value' => $event->toArray(),
            ]);
        }
    }

    /**
     * Emit the AG-UI start/args/end frames for a single tool call.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function startAgUiToolCall(object $state, string $toolCallId, string $toolCallName, array $arguments): Generator
    {
        $start = [
            'type' => 'TOOL_CALL_START',
            'toolCallId' => $toolCallId,
            'toolCallName' => $toolCallName,
        ];

        if ($state->lastMessageId !== null) {
            $start['parentMessageId'] = $state->lastMessageId;
        }

        yield $this->toAgUiProtocolFrame($start);
        yield $this->toAgUiProtocolFrame([
            'type' => 'TOOL_CALL_ARGS',
            'toolCallId' => $toolCallId,
            'delta' => $arguments === [] ? '{}' : $this->encodeAgUiValue($arguments),
        ]);
        yield $this->toAgUiProtocolFrame([
            'type' => 'TOOL_CALL_END',
            'toolCallId' => $toolCallId,
        ]);

        $state->toolCallIds[$toolCallId] = true;
    }

    /**
     * Start an AG-UI text message, closing any open message first.
     */
    protected function startAgUiTextMessage(object $state, string $messageId): Generator
    {
        if ($state->activeTextSourceId === $messageId) {
            return;
        }

        yield from $this->closeAgUiMessages($state);

        $resolved = $this->resolveAgUiMessageId($state, $messageId);

        $state->activeTextSourceId = $messageId;
        $state->activeTextMessageId = $resolved;
        $state->lastMessageId = $resolved;

        yield $this->toAgUiProtocolFrame([
            'type' => 'TEXT_MESSAGE_START',
            'messageId' => $resolved,
            'role' => 'assistant',
        ]);
    }

    /**
     * End the active AG-UI text message.
     */
    protected function endAgUiTextMessage(object $state): Generator
    {
        if ($state->activeTextMessageId === null) {
            return;
        }

        yield $this->toAgUiProtocolFrame([
            'type' => 'TEXT_MESSAGE_END',
            'messageId' => $state->activeTextMessageId,
        ]);

        $state->messageIdUses[$state->activeTextSourceId] = ($state->messageIdUses[$state->activeTextSourceId] ?? 0) + 1;
        $state->activeTextMessageId = null;
        $state->activeTextSourceId = null;
    }

    /**
     * Start an AG-UI reasoning message, closing any open message first.
     */
    protected function startAgUiReasoningMessage(object $state, string $messageId): Generator
    {
        if ($state->activeReasoningSourceId === $messageId) {
            return;
        }

        yield from $this->closeAgUiMessages($state);

        $resolved = $this->resolveAgUiMessageId($state, $messageId);

        $state->activeReasoningSourceId = $messageId;
        $state->activeReasoningMessageId = $resolved;

        yield $this->toAgUiProtocolFrame([
            'type' => 'REASONING_START',
            'messageId' => $resolved,
        ]);
        yield $this->toAgUiProtocolFrame([
            'type' => 'REASONING_MESSAGE_START',
            'messageId' => $resolved,
            'role' => 'reasoning',
        ]);
    }

    /**
     * End the active AG-UI reasoning message.
     */
    protected function endAgUiReasoningMessage(object $state): Generator
    {
        if ($state->activeReasoningMessageId === null) {
            return;
        }

        yield $this->toAgUiProtocolFrame([
            'type' => 'REASONING_MESSAGE_END',
            'messageId' => $state->activeReasoningMessageId,
        ]);
        yield $this->toAgUiProtocolFrame([
            'type' => 'REASONING_END',
            'messageId' => $state->activeReasoningMessageId,
        ]);

        $state->messageIdUses[$state->activeReasoningSourceId] = ($state->messageIdUses[$state->activeReasoningSourceId] ?? 0) + 1;
        $state->activeReasoningMessageId = null;
        $state->activeReasoningSourceId = null;
    }

    /**
     * Resolve a unique AG-UI message id, deriving a fresh id when one is reopened.
     */
    protected function resolveAgUiMessageId(object $state, string $messageId): string
    {
        $uses = $state->messageIdUses[$messageId] ?? 0;

        return $uses === 0 ? $messageId : $messageId.'#'.$uses;
    }

    /**
     * Close any active AG-UI text or reasoning message.
     */
    protected function closeAgUiMessages(object $state): Generator
    {
        yield from $this->endAgUiReasoningMessage($state);
        yield from $this->endAgUiTextMessage($state);
    }

    /**
     * Finish the active AG-UI run successfully.
     */
    protected function finishAgUiRun(object $state, string $threadId, string $runId): string
    {
        $state->runFinished = true;

        return $this->toAgUiProtocolFrame([
            'type' => 'RUN_FINISHED',
            'threadId' => $threadId,
            'runId' => $runId,
            'outcome' => ['type' => 'success'],
        ]);
    }

    /**
     * Get the protocol content for a tool result.
     */
    protected function encodeAgUiContent(ToolResult $event): string
    {
        if ($event->denied) {
            return $event->error ?? 'The user rejected this tool call.';
        }

        if (! $event->successful) {
            return $event->error ?? 'The tool call failed.';
        }

        return is_string($event->toolResult->result)
            ? $event->toolResult->result
            : $this->encodeAgUiValue($event->toolResult->result);
    }

    /**
     * JSON encode a value embedded as AG-UI string content.
     */
    protected function encodeAgUiValue(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Encode an AG-UI event as a server-sent event frame.
     *
     * @param  array<string, mixed>  $event
     */
    protected function toAgUiProtocolFrame(array $event): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

        $json = json_encode($event, $flags);

        // json_encode can still fail (e.g. nesting beyond the depth limit); fall back to a valid frame that preserves the event type rather than emitting a malformed empty data line.
        if ($json === false) {
            $json = (string) json_encode(['type' => $event['type'] ?? 'RAW'], $flags);
        }

        return 'data: '.$json."\n\n";
    }
}
