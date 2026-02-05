<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ModelDeleteResponse implements Arrayable, JsonSerializable
{
    /**
     * Create a new model delete response instance.
     */
    public function __construct(
        public string $id,
        public string $object,
        public bool $deleted,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'object' => $this->object,
            'deleted' => $this->deleted,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
