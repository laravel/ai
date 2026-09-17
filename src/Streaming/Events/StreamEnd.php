<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Usage;

class StreamEnd extends StreamEvent
{
    /**
     * @param  array<int, array{blocks: array<array-key, mixed>, tool_call_ids: array<int, string>}>  $steps  every assistant step of the turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public string $reason,
        public Usage $usage,
        public int $timestamp,
        public array $steps = [],
    ) {
        //
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     */
    public static function combineUsage(Collection|array $events): Usage
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(StreamEnd::class)
            ->values()
            ->map(fn (StreamEnd $event): Usage => $event->usage)
            ->reduce(fn ($a, $b) => $a->add($b), new Usage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'stream_end',
            'reason' => $this->reason,
            'usage' => $this->usage instanceof Usage
                ? $this->usage->toArray()
                : null,
            'timestamp' => $this->timestamp,
        ];
    }
}
