<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class BatchListResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new batch list response instance.
     *
     * @param  array<BatchResponse>  $batches
     */
    public function __construct(
        public array $batches,
        public bool $hasMore,
        public ?string $firstId = null,
        public ?string $lastId = null,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'batches' => array_map(fn ($batch) => $batch->toArray(), $this->batches),
            'has_more' => $this->hasMore,
            'first_id' => $this->firstId,
            'last_id' => $this->lastId,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the number of batches in the response.
     */
    public function count(): int
    {
        return count($this->batches);
    }

    /**
     * Get an iterator for the object.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->batches as $batch) {
            yield $batch;
        }
    }
}
