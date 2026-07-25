<?php

namespace Laravel\Ai\Approvals;

class Decisions
{
    /**
     * Create a new approval decisions instance.
     *
     * @param  array<string, Decision>  $decisions
     */
    private function __construct(protected array $decisions) {}

    /**
     * Create approval decisions from an ID-keyed decision map.
     *
     * @param  array<string, Decision|bool>  $decisions
     */
    public static function from(array $decisions): self
    {
        return new self(Decision::normalize($decisions));
    }

    /**
     * Get all approval decisions.
     *
     * @return array<string, Decision>
     */
    public function all(): array
    {
        return $this->decisions;
    }

    /**
     * Get the decision for the given tool call ID.
     */
    public function get(string $toolCallId): ?Decision
    {
        return $this->decisions[$toolCallId] ?? null;
    }

    /**
     * Approve every tool call without an explicit decision.
     */
    public function approveRemaining(): self
    {
        return self::from([
            ...$this->decisions,
            '*' => Decision::approve(),
        ]);
    }

    /**
     * Reject every tool call without an explicit decision.
     */
    public function rejectRemaining(?string $result = null): self
    {
        return self::from([
            ...$this->decisions,
            '*' => Decision::reject($result),
        ]);
    }
}
