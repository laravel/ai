<?php

namespace Laravel\Ai\Approvals;

use InvalidArgumentException;

class Decision
{
    /**
     * @param  array<string, mixed>|null  $arguments
     * @param  array<string, Decision>  $decisions  keyed by tool call id when this decision bundles a collection
     */
    private function __construct(
        public ?string $action = null,
        public ?string $result = null,
        public ?array $arguments = null,
        public array $decisions = [],
    ) {}

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
        return new self('reject', result: $result);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    /**
     * Bundle one decision per tool call id, accepting booleans as shorthand and a '*' wildcard for undecided calls.
     *
     * @param  array<string, Decision|bool>  $decisions
     */
    public static function collection(array $decisions): self
    {
        $normalized = [];

        foreach ($decisions as $id => $decision) {
            $decision = match (true) {
                $decision === true => self::approve(),
                $decision === false => self::reject(),
                $decision instanceof self => $decision,
                default => throw new InvalidArgumentException('Tool approval decisions must be Decision instances or booleans.'),
            };

            if ($id === '*' && $decision->isEdited()) {
                throw new InvalidArgumentException('The wildcard decision may not use the edit action.');
            }

            $normalized[$id] = $decision;
        }

        return new self(decisions: $normalized);
    }

    public function isRejected(): bool
    {
        return $this->action === 'reject';
    }

    public function isEdited(): bool
    {
        return $this->action === 'edit';
    }
}
