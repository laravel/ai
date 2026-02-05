<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Traversable;

class ModerationResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new moderation response instance.
     *
     * @param  array<int, ModerationResult>  $results
     */
    public function __construct(public array $results, public Meta $meta) {}

    /**
     * Get the first result in the response.
     */
    public function first(): ModerationResult
    {
        return $this->results[0];
    }

    /**
     * Determine if any of the inputs were flagged.
     */
    public function flagged(): bool
    {
        foreach ($this->results as $result) {
            if ($result->flagged) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'results' => array_map(fn ($result) => $result->toArray(), $this->results),
            'meta' => $this->meta,
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
     * Get the number of moderation results in the response.
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Get an iterator for the object.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->results as $result) {
            yield $result;
        }
    }
}
