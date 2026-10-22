<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Contracts\Support\Arrayable;

class PendingApproval implements Arrayable
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
        public readonly ?array $data = null,
    ) {}

    /**
     * Determine whether the pause is waiting on values rather than a decision.
     */
    public function isInteractive(): bool
    {
        return $this->schema !== null;
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
                'schema' => $this->schema,
                'data' => $this->data,
            ], fn ($value) => $value !== null),
        ];
    }
}
