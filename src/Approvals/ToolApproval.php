<?php

namespace Laravel\Ai\Approvals;

use InvalidArgumentException;

class ToolApproval
{
    /**
     * @param  array<string, Approval>  $decisions
     * @param  Approval|null  $default  the decision for any pending call not explicitly decided
     */
    public function __construct(
        public array $decisions = [],
        public ?Approval $default = null,
    ) {}

    /**
     * Approve every pending tool call that is not explicitly decided.
     */
    public static function approveAll(): self
    {
        return new self(default: Approval::approve());
    }

    /**
     * Reject every pending tool call that is not explicitly decided.
     */
    public static function rejectAll(?string $result = null): self
    {
        return new self(default: Approval::reject($result));
    }

    /**
     * @param  array<string, Approval|bool>  $decisions
     */
    public static function from(array $decisions): self
    {
        $normalized = [];
        $default = null;

        foreach ($decisions as $id => $decision) {
            $decision = match (true) {
                $decision === true => Approval::approve(),
                $decision === false => Approval::reject(),
                $decision instanceof Approval => $decision,
                default => throw new InvalidArgumentException('Tool approval decisions must be Approval instances or booleans.'),
            };

            // A wildcard decision stands in for every pending call that is not explicitly decided...
            if ($id === '*') {
                if ($decision->action === 'edit') {
                    throw new InvalidArgumentException('The wildcard decision may not use the edit action.');
                }

                $default = $decision;

                continue;
            }

            $normalized[$id] = $decision;
        }

        return new self($normalized, $default);
    }
}
