<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Traversable;

class RerankingResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new reranking response instance.
     *
     * @param  array<int, RankedDocument>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly Meta $meta,
    ) {}

    /**
     * Get the top-ranked result.
     */
    public function first(): ?RankedDocument
    {
        return $this->results[0] ?? null;
    }

    /**
     * Get the documents in their reranked order.
     */
    public function documents(): Collection
    {
        return (new Collection($this->results))->map->document;
    }

    /**
     * Get the number of results in the response.
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Get the results as a collection.
     */
    public function collect(): Collection
    {
        return new Collection($this->results);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'results' => $this->results,
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
     * Get an iterator for the results.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->results as $result) {
            yield $result;
        }
    }
}
