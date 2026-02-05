<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Traversable;

class FineTuningCheckpointListResponse implements Arrayable, Countable, IteratorAggregate
{
    /**
     * Create a new fine-tuning checkpoint list response instance.
     *
     * @param  array<FineTuningCheckpointResponse>  $checkpoints
     */
    public function __construct(
        public array $checkpoints,
        public bool $hasMore = false,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'checkpoints' => array_map(fn ($checkpoint) => (array) $checkpoint, $this->checkpoints),
            'has_more' => $this->hasMore,
        ];
    }

    /**
     * Get the number of checkpoints in the response.
     */
    public function count(): int
    {
        return count($this->checkpoints);
    }

    /**
     * Get an iterator for the checkpoints.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->checkpoints as $checkpoint) {
            yield $checkpoint;
        }
    }
}
