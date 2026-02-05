<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class BatchRequestCounts implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $total,
        public int $completed,
        public int $failed,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'total' => $this->total,
            'completed' => $this->completed,
            'failed' => $this->failed,
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
