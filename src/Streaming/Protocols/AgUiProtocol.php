<?php

namespace Laravel\Ai\Streaming\Protocols;

use Generator;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
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

use function Laravel\Ai\ulid;

/**
 * The Agent User Interaction (AG-UI) protocol.
 *
 * See: https://docs.ag-ui.com/concepts/events
 */
class AgUiProtocol extends StreamProtocol
{
    protected int $step = 0;

    public function __construct(
        protected ?string $threadId = null,
        protected ?string $runId = null,
    ) {
        //
    }

    /**
     * {@inheritdoc}
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $this->started = false;
        $this->errored = false;
        $this->step = 0;

        $continuesThread = $this->threadId !== null;

        $this->threadId ??= $response->conversationId ?? ulid();
        $this->runId ??= $response->invocationId;

        $toolCalls = [];
        $interrupts = [];

        foreach ($response as $event) {
            // Start the run once, wrapping each subsequent provider step in step events...
            if ($event instanceof StreamStart) {
                if ($this->started) {
                    yield $this->stepPart('STEP_FINISHED');
                    yield $this->stepPart('STEP_STARTED', next: true);
                } else {
                    yield from $this->runStartedParts();
                }

                continue;
            }

            // Track tool calls initiated within this run.
            if ($event instanceof ToolCall) {
                $toolCalls[$event->toolCall->id] = true;
            }

            if ($event instanceof Error) {
                $this->errored = true;
            }

            // A result without a local call is valid only when continuing a thread the client already holds the call for...
            if ($event instanceof ToolResult
                && ! isset($toolCalls[$event->toolResult->id])
                && ! $continuesThread) {
                continue;
            }

            // The run pauses on approvals, so hold them for the terminal interrupt outcome...
            if ($event instanceof ToolApprovalRequest) {
                foreach ($event->pendingApprovals as $pendingApproval) {
                    $interrupts[] = $this->interrupt($pendingApproval);
                }

                continue;
            }

            foreach ($this->map($event) as $part) {
                yield from $this->part($part);
            }
        }

        if ($this->started && ! $this->errored) {
            yield $this->stepPart('STEP_FINISHED');
            yield $this->runFinishedPart($interrupts);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->part(['type' => 'RUN_ERROR', 'message' => 'An error occurred.']);
    }

    /**
     * {@inheritdoc}
     */
    protected function headers(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
        ];
    }

    /**
     * Get the given protocol part, preceded by the run started events when the run has not begun yet.
     *
     * @param  array<string, mixed>  $part
     */
    protected function part(array $part): Generator
    {
        if (! $this->started) {
            yield from $this->runStartedParts();
        }

        yield $part;
    }

    /**
     * Get the events that begin the run and its first step.
     */
    protected function runStartedParts(): Generator
    {
        $this->started = true;

        yield [
            'type' => 'RUN_STARTED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
        ];

        yield $this->stepPart('STEP_STARTED', next: true);
    }

    /**
     * Get the event that starts or finishes the current step.
     *
     * @return array<string, mixed>
     */
    protected function stepPart(string $type, bool $next = false): array
    {
        return [
            'type' => $type,
            'stepName' => (string) ($next ? ++$this->step : $this->step),
        ];
    }

    /**
     * Get the event that finishes the run, interrupting it when approvals are pending.
     *
     * @param  array<int, array<string, mixed>>  $interrupts
     * @return array<string, mixed>
     */
    protected function runFinishedPart(array $interrupts): array
    {
        return [
            'type' => 'RUN_FINISHED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            ...($interrupts === [] ? [] : [
                'outcome' => ['type' => 'interrupt', 'interrupts' => $interrupts],
            ]),
        ];
    }

    /**
     * Get the interrupt that represents the given pending approval.
     *
     * @return array<string, mixed>
     */
    protected function interrupt(PendingApproval $pendingApproval): array
    {
        return array_filter([
            'id' => $pendingApproval->id,
            'reason' => 'tool_call',
            'message' => $pendingApproval->reason,
            'toolCallId' => $pendingApproval->id,
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean']],
                'required' => ['approved'],
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * Get the protocol parts that represent the given event.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function map(StreamEvent $event): array
    {
        return match (true) {
            $event instanceof TextStart => [[
                'type' => 'TEXT_MESSAGE_START',
                'messageId' => $event->messageId,
                'role' => 'assistant',
            ]],
            $event instanceof TextDelta => [[
                'type' => 'TEXT_MESSAGE_CONTENT',
                'messageId' => $event->messageId,
                'delta' => $event->delta,
            ]],
            $event instanceof TextEnd => [[
                'type' => 'TEXT_MESSAGE_END',
                'messageId' => $event->messageId,
            ]],
            $event instanceof ReasoningStart => [
                ['type' => 'REASONING_START', 'messageId' => $event->reasoningId],
                ['type' => 'REASONING_MESSAGE_START', 'messageId' => $event->reasoningId, 'role' => 'assistant'],
            ],
            $event instanceof ReasoningDelta => [[
                'type' => 'REASONING_MESSAGE_CONTENT',
                'messageId' => $event->reasoningId,
                'delta' => $event->delta,
            ]],
            $event instanceof ReasoningEnd => [
                ['type' => 'REASONING_MESSAGE_END', 'messageId' => $event->reasoningId],
                ['type' => 'REASONING_END', 'messageId' => $event->reasoningId],
            ],
            $event instanceof ToolCall => [
                ['type' => 'TOOL_CALL_START', 'toolCallId' => $event->toolCall->id, 'toolCallName' => $event->toolCall->name],
                ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $event->toolCall->id, 'delta' => json_encode($event->toolCall->arguments)],
                ['type' => 'TOOL_CALL_END', 'toolCallId' => $event->toolCall->id],
            ],
            $event instanceof ToolResult => [[
                'type' => 'TOOL_CALL_RESULT',
                'messageId' => $event->toolResult->resultId ?? $event->id,
                'toolCallId' => $event->toolResult->id,
                'content' => $this->content($event),
                'role' => 'tool',
            ]],
            $event instanceof Error => [[
                'type' => 'RUN_ERROR',
                'message' => $event->message,
                'code' => $event->type,
            ]],
            $event instanceof Citation => $this->citationParts($event),
            $event instanceof ProviderToolEvent => [[
                'type' => 'CUSTOM',
                'name' => 'provider-tool',
                'value' => [
                    'itemId' => $event->itemId,
                    'type' => $event->type,
                    'data' => $event->data,
                    'status' => $event->status,
                ],
            ]],
            default => [],
        };
    }

    /**
     * Get the protocol parts that represent the given citation event.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function citationParts(Citation $event): array
    {
        return match (true) {
            $event->citation instanceof UrlCitation => [[
                'type' => 'CUSTOM',
                'name' => 'citation',
                'value' => array_filter([
                    'url' => $event->citation->url,
                    'title' => $event->citation->title,
                ], fn ($value) => $value !== null),
            ]],
            default => [],
        };
    }

    /**
     * Get the tool message content for the given tool result event.
     */
    protected function content(ToolResult $event): string
    {
        if (! $event->successful) {
            return $event->error ?? 'The tool call failed.';
        }

        return is_string($event->toolResult->result)
            ? $event->toolResult->result
            : (string) json_encode($event->toolResult->result);
    }
}
