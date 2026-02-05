<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class BatchError implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $param = null,
        public ?int $line = null,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'param' => $this->param,
            'line' => $this->line,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
