<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
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
            toolResults: $events->whereInstanceOf(ToolResult::class)
                ->reject(fn (ToolResult $event): bool => $event->preliminary)
                ->map->toolResult,
        );

        $this->events = $events;

        $this->reasoning = ReasoningDelta::combine($events);

        // A streamed run only ever sees its citations as events, not on the parsed body...
        $this->meta->citations = Citation::combine($events);

        $this->withPendingApprovals(
            $events->whereInstanceOf(ToolApprovalRequest::class)
                ->flatMap(fn (ToolApprovalRequest $event) => $event->pendingApprovals)
                ->values()
        );
    }

    /**
     * Get every assistant step of the turn with the raw provider state needed to replay it.
     *
     * @return array<int, array{blocks: array<array-key, mixed>, tool_call_ids: array<int, string>}>
     */
    public function providerSteps(): array
    {
        $steps = $this->events->whereInstanceOf(ToolApprovalRequest::class)->last()?->steps
            ?? $this->events->whereInstanceOf(StreamEnd::class)->last()?->steps
            ?? [];

        return collect($steps)->contains(fn (array $step): bool => filled($step['blocks'] ?? [])) ? $steps : [];
    }

    /**
     * Get every assistant step of a paused turn with the raw provider state needed to replay it.
     *
     * @return array<int, array{blocks: array<array-key, mixed>, tool_call_ids: array<int, string>}>
     *
     * @deprecated Use providerSteps().
     */
    public function pausedSteps(): array
    {
        return $this->events->whereInstanceOf(ToolApprovalRequest::class)->last()?->steps ?? [];
    }

    /**
     * Get the raw provider replay state for the paused assistant turn, if any.
     *
     * @return array<array-key, mixed>
     *
     * @deprecated Use providerSteps().
     */
    public function pausedProviderContentBlocks(): array
    {
        return $this->events->whereInstanceOf(ToolApprovalRequest::class)->last()?->providerContentBlocks ?? [];
    }
}
