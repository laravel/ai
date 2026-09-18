<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

abstract class Answer implements Arrayable, JsonSerializable
{
    /**
     * Get the instance as an array.
     */
    abstract public function toArray(): array;

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
