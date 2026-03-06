<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ToolCall implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $resultId = null,
        public ?string $reasoningId = null,
        public ?array $reasoningSummary = null,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result_id' => $this->resultId,
            'reasoning_id' => $this->reasoningId,
            'reasoning_summary' => $this->reasoningSummary,
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
