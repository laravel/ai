<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class StoreFileCounts implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly int $completed,
        public readonly int $pending,
        public readonly int $failed,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'pending' => $this->pending,
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
