<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Contracts\Support\Arrayable;

class PendingApproval implements Arrayable
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ?string $reason = null,
    ) {}

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
        ];
    }
}
