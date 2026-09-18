<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Concerns\JoinsReasoning;

class ReasoningDelta extends StreamEvent
{
    use JoinsReasoning;

    public function __construct(
        public string $id,
        public string $reasoningId,
        public string $delta,
        public int $timestamp,
        public ?array $summary = null,
    ) {
        //
    }

    /**
     * Combine reasoning deltas by block, separating each block with a blank line.
     */
    public static function combine(Collection|array $events): string
    {
        return static::joinReasoning(
            Collection::wrap($events)
                ->whereInstanceOf(ReasoningDelta::class)
                ->groupBy(fn (ReasoningDelta $event) => $event->reasoningId)
                ->map(fn (Collection $deltas) => $deltas->pluck('delta')->implode(''))
                ->values()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'reasoning_delta',
            'reasoning_id' => $this->reasoningId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }
}
