<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class ModelListResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new model list response instance.
     *
     * @param  array<int, ModelResponse>  $models
     */
    public function __construct(public array $models) {}

    /**
     * Get the first model in the response.
     */
    public function first(): ?ModelResponse
    {
        return $this->models[0] ?? null;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'models' => array_map(fn ($model) => $model->toArray(), $this->models),
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
     * Get the number of models in the response.
     */
    public function count(): int
    {
        return count($this->models);
    }

    /**
     * Get an iterator for the object.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->models as $model) {
            yield $model;
        }
    }
}
