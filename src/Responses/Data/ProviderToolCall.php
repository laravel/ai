<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ProviderToolCall implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
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
