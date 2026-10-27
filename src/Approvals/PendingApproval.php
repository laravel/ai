<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Contracts\Support\Arrayable;

class PendingApproval implements Arrayable
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $inputSchema
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ?string $reason = null,
        public readonly ?array $inputSchema = null,
        public readonly ?array $data = null,
    ) {}

    /**
     * Determine whether the pause is waiting on values rather than a decision.
     */
    public function requiresInput(): bool
    {
        return $this->inputSchema !== null;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
            ...array_filter([
                'input_schema' => $this->inputSchema,
                'data' => $this->data,
            ], fn ($value) => $value !== null),
        ];
    }
}
