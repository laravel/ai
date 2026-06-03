<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

class StreamedAgentResponse extends AgentResponse
{
    public Collection $events;

    public function __construct(string $invocationId, Collection $events, Meta $meta)
    {
        $meta->searchQueries = $meta->searchQueries
            ->merge($this->extractSearchQueries($events))
            ->unique()
            ->values();

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
    }

    protected function extractSearchQueries(Collection $events): Collection
    {
        return $events
            ->whereInstanceOf(ProviderToolEvent::class)
            ->filter(fn (ProviderToolEvent $event): bool => $event->type === 'web_search_call')
            ->filter(fn (ProviderToolEvent $event): bool => data_get($event->data, 'action.type') === 'search')
            ->flatMap(function (ProviderToolEvent $event): array {
                return collect([
                    ...Arr::wrap(data_get($event->data, 'action.queries')),
                    data_get($event->data, 'action.query'),
                ])
                    ->filter(fn (mixed $query): bool => is_string($query) && $query !== '')
                    ->all();
            })
            ->values();
    }
}
