<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

class StreamedAgentResponse extends AgentResponse
{
    /** @var Collection<int, StreamEvent> */
    public Collection $events;

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public function __construct(string $invocationId, Collection $events, Meta $meta)
    {
        parent::__construct(
            $invocationId,
            TextDelta::combine($events),
            StreamEnd::combineUsage($events),
            $meta,
        );

        $this->withToolCallsAndResults(
            toolCalls: $events->whereInstanceOf(ToolCall::class)->map->toolCall,
            toolResults: $events->whereInstanceOf(ToolResult::class)->map->toolResult,
        );

        $this->events = $events;

        $this->withPendingApprovals(
            $events->whereInstanceOf(ToolApprovalRequest::class)
                ->flatMap(fn (ToolApprovalRequest $event) => $event->pendingApprovals)
                ->values()
        );
    }

    /**
     * Get the raw provider replay state for the paused assistant turn, if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pausedProviderContentBlocks(): array
    {
        return $this->events->whereInstanceOf(ToolApprovalRequest::class)->last()?->providerContentBlocks ?? [];
    }

    /**
     * Get the raw provider replay state for the steps that ran before the paused step, if any.
     *
     * @return array<int, array{tool_call_ids: array<int, string>, blocks: array<int|string, mixed>, content: string}>
     */
    public function precedingProviderContentBlockSteps(): array
    {
        return $this->events->whereInstanceOf(ToolApprovalRequest::class)->last()?->precedingProviderContentBlockSteps ?? [];
    }
}
