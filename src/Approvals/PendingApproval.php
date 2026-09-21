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
     * Determine whether a stored tool call is still awaiting an approval decision.
     *
     * @param  array<string, mixed>  $toolCall
     */
    public static function isPending(array $toolCall): bool
    {
        return array_key_exists('approval_reason', $toolCall) && ! static::isAnswered($toolCall);
    }

    /**
     * Determine whether a stored tool call has been answered by its tool.
     *
     * @param  array<string, mixed>  $toolCall
     */
    public static function isAnswered(array $toolCall): bool
    {
        return array_key_exists('result', $toolCall);
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
        ];
    }
}
