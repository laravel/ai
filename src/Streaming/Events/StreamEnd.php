<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;

class StreamEnd extends StreamEvent
{
    /**
     * @param  Collection<int, Step>  $steps  replay state for the completed turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public string $reason,
        public TextUsage $usage,
        public int $timestamp,
        public Collection $steps = new Collection,
    ) {
        //
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     */
    public static function combineUsage(Collection|array $events): TextUsage
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(StreamEnd::class)
            ->values()
            ->map(fn (StreamEnd $event): TextUsage => $event->usage)
            ->reduce(fn ($a, $b) => $a->add($b), new TextUsage);
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
            'usage' => $this->usage instanceof TextUsage
                ? $this->usage->toArray()
                : null,
            'timestamp' => $this->timestamp,
        ];
    }
}
