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
        public mixed $result,
        public ?string $resultId = null,
        public bool $denied = false,
        public bool $failed = false,
    ) {}

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            arguments: $data['arguments'],
            result: $data['result'],
            resultId: $data['result_id'] ?? null,
            denied: $data['denied'] ?? false,
            failed: $data['failed'] ?? false,
        );
    }

    /**
     * Determine if the tool call ran and produced its own result.
     */
    public function successful(): bool
    {
        return ! $this->denied && ! $this->failed;
    }

    /**
     * Get the message explaining why the tool call did not succeed.
     */
    public function error(): ?string
    {
        return $this->successful() || ! is_string($this->result) ? null : $this->result;
    }

    /**
     * Get the instance as an array, only including the denied and failed keys when they apply.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_id' => $this->resultId,
            ...($this->denied ? ['denied' => true] : []),
            ...($this->failed ? ['failed' => true] : []),
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
