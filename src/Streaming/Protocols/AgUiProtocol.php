<?php

namespace Laravel\Ai\Streaming\Protocols;

use Generator;
use Illuminate\Support\Arr;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
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

use function Laravel\Ai\ulid;

/**
 * The Agent User Interaction (AG-UI) protocol.
 *
 * See: https://docs.ag-ui.com/concepts/events
 */
class AgUiProtocol extends StreamProtocol
{
    protected int $step = 0;

    protected bool $finished = false;

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
        $this->finished = false;
        $this->step = 0;

        $this->threadId ??= $response->conversationId ?? ulid();
        $this->runId ??= $response->invocationId;

        $toolCalls = [];
        $usage = null;
        $reason = null;

        foreach ($response as $event) {
            if ($this->finished) {
                continue;
            }

            if ($event instanceof StreamStart) {
                if ($this->started) {
                    yield $this->stepFinishedPart();
                    yield $this->stepStartedPart();
                } else {
                    yield from $this->runStartedParts();
                }

                continue;
            }

            if ($event instanceof ToolCall) {
                $toolCalls[$event->toolCall->id] = true;
            }

            if ($event instanceof Error) {
                $this->errored = true;
            }

            // A result replayed from an earlier turn has no call the client can attach it to, so restate the call first...
            if ($event instanceof ToolResult && ! isset($toolCalls[$event->toolResult->id])) {
                $toolCalls[$event->toolResult->id] = true;

                foreach ($this->toolCallParts($event->toolResult) as $part) {
                    yield from $this->part($part);
                }
            }

            // The run pauses on approvals, so finish it immediately with the outcome the client resumes from...
            if ($event instanceof ToolApprovalRequest) {
                yield from $this->part($this->stepFinishedPart());

                yield $this->runFinishedPart([
                    'outcome' => ['type' => 'interrupt', 'interrupts' => $this->interrupts($event)],
                ]);

                $this->finished = true;

                continue;
            }

            // Hold each step's usage and reason for the terminal run finished event...
            if ($event instanceof StreamEnd) {
                $usage = ($usage ?? new Usage)->add($event->usage);
                $reason = $event->reason;

                continue;
            }

            foreach ($this->map($event) as $part) {
                yield from $this->part($part);
            }
        }

        if ($this->started && ! $this->errored && ! $this->finished) {
            yield $this->stepFinishedPart();
            yield $this->runFinishedPart($this->completion($usage, $reason));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function maskedErrorParts(): Generator
    {
        // The interrupt outcome already ended the run, so a trailing error would follow a terminal event...
        if ($this->finished) {
            return;
        }

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

        yield $this->stepStartedPart();
    }

    /**
     * Get the event that starts the next step.
     *
     * @return array<string, mixed>
     */
    protected function stepStartedPart(): array
    {
        return ['type' => 'STEP_STARTED', 'stepName' => (string) ++$this->step];
    }

    /**
     * Get the event that finishes the current step.
     *
     * @return array<string, mixed>
     */
    protected function stepFinishedPart(): array
    {
        return ['type' => 'STEP_FINISHED', 'stepName' => (string) $this->step];
    }

    /**
     * Get the event that finishes the run with the given additional attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function runFinishedPart(array $attributes = []): array
    {
        return [
            'type' => 'RUN_FINISHED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            ...$attributes,
        ];
    }

    /**
     * Get the run finished attributes that report how the run completed.
     *
     * @return array<string, mixed>
     */
    protected function completion(?Usage $usage, ?string $reason): array
    {
        return [
            ...($usage !== null ? ['usage' => [[
                'inputTokens' => $usage->promptTokens,
                'outputTokens' => $usage->completionTokens,
                'totalTokens' => $usage->promptTokens + $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedInputTokens' => $usage->cacheReadInputTokens,
            ]]] : []),
            ...($reason === null ? [] : ['metadata' => ['finishReason' => $reason]]),
        ];
    }

    /**
     * Get the interrupts that represent the given approval request's pending approvals.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function interrupts(ToolApprovalRequest $event): array
    {
        return $event->pendingApprovals->map(fn (PendingApproval $approval) => Arr::whereNotNull([
            'id' => $approval->id,
            'reason' => 'tool_call',
            'message' => $approval->reason,
            'toolCallId' => $approval->id,
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean']],
                'required' => ['approved'],
            ],
        ]))->all();
    }

    /**
     * Get the protocol parts that represent the given tool call.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toolCallParts(Data\ToolCall|Data\ToolResult $call): array
    {
        return [
            ['type' => 'TOOL_CALL_START', 'toolCallId' => $call->id, 'toolCallName' => $call->name],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $call->id, 'delta' => $this->json((object) $call->arguments)],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => $call->id],
        ];
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
                ['type' => 'REASONING_MESSAGE_START', 'messageId' => $event->reasoningId, 'role' => 'reasoning'],
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
            $event instanceof ToolCall => $this->toolCallParts($event->toolCall),
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
                'value' => Arr::whereNotNull([
                    'provider' => $event->provider,
                    'itemId' => $event->itemId,
                    'type' => $event->type,
                    'data' => $event->data,
                    'status' => $event->status,
                ]),
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
                'value' => Arr::whereNotNull([
                    'url' => $event->citation->url,
                    'title' => $event->citation->title,
                ]),
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
            : $this->json($event->toolResult->result);
    }
}
