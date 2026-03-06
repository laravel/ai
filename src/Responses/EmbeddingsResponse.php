<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Traversable;

class EmbeddingsResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new embeddings response instance.
     *
     * @param  array<int, array<float>>
     */
    public function __construct(public array $embeddings, public int $tokens, public Meta $meta) {}

    /**
     * Get the first set of embeddings in the response.
     */
    public function first(): array
    {
        return $this->embeddings[0];
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'embeddings' => $this->embeddings,
            'tokens' => $this->tokens,
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
     * Get the number of generated embeddings in the response.
     */
    public function count(): int
    {
        return count($this->embeddings);
    }

    /**
     * Get an iterator for the object.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->embeddings as $embedding) {
            yield $embedding;
        }
    }
}
