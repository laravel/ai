<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Traversable;

class FineTuningEventListResponse implements Arrayable, Countable, IteratorAggregate
{
    /**
     * Create a new fine-tuning event list response instance.
     *
     * @param  array<FineTuningEventResponse>  $events
     */
    public function __construct(
        public array $events,
        public bool $hasMore = false,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'events' => array_map(fn ($event) => (array) $event, $this->events),
            'has_more' => $this->hasMore,
        ];
    }

    /**
     * Get the number of events in the response.
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Get an iterator for the events.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->events as $event) {
            yield $event;
        }
    }
}
