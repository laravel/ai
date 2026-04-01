<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ToolResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public $result,
        public ?string $resultId = null,
        public ?array $meta = null,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_id' => $this->resultId,
        ];

        if (! is_null($this->meta)) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
