<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ModelResponse implements Arrayable, JsonSerializable
{
    /**
     * Create a new model response instance.
     */
    public function __construct(
        public string $id,
        public string $object,
        public int $created,
        public string $ownedBy,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'object' => $this->object,
            'created' => $this->created,
            'owned_by' => $this->ownedBy,
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
