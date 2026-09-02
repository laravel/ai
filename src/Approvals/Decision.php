<?php

namespace Laravel\Ai\Approvals;

use InvalidArgumentException;

class Decision
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    private function __construct(
        public readonly string $action,
        public readonly ?string $result = null,
        public readonly ?array $arguments = null,
    ) {}

    /**
     * Approve the pending tool call.
     */
    public static function approve(): self
    {
        return new self('approve');
    }

    /**
     * Reject the pending tool call.
     */
    public static function reject(?string $result = null): self
    {
        return new self('reject', result: blank($result) ? null : $result);
    }

    /**
     * Approve the pending tool call with its arguments replaced wholesale.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    /**
     * Approve the pending tool call with the given arguments merged into its own.
     *
     * Where edit() replaces the call's arguments wholesale, this changes only
     * the keys given. The caller never re-supplies what the model already
     * provided, since the resume reads it back from the paused turn.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function merge(array $arguments): self
    {
        return new self('merge', arguments: $arguments);
    }

    /**
     * A blanket approval for every pending tool call.
     */
    public static function approveAll(): Decisions
    {
        return Decisions::from(['*' => self::approve()]);
    }

    /**
     * A blanket rejection for every pending tool call.
     */
    public static function rejectAll(?string $result = null): Decisions
    {
        return Decisions::from(['*' => self::reject($result)]);
    }

    /**
     * Normalize an id-keyed decision map, accepting booleans as shorthand and a '*' wildcard for undecided calls.
     *
     * @param  array<string, Decision|bool>  $decisions
     * @return array<string, Decision>
     */
    public static function normalize(array $decisions): array
    {
        if ($decisions === []) {
            throw new InvalidArgumentException('Tool approval decisions may not be empty.');
        }

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

            if ($id === '*' && $decision->isMerged()) {
                throw new InvalidArgumentException('The wildcard decision may not use the merge action.');
            }

            $normalized[$id] = $decision;
        }

        return $normalized;
    }

    /**
     * Determine whether the tool call was approved.
     */
    public function isApproved(): bool
    {
        return $this->action === 'approve';
    }

    /**
     * Determine whether the tool call was rejected.
     */
    public function isRejected(): bool
    {
        return $this->action === 'reject';
    }

    /**
     * Determine whether the tool call arguments were edited.
     */
    public function isEdited(): bool
    {
        return $this->action === 'edit';
    }

    /**
     * Determine whether the tool call's arguments were merged into.
     */
    public function isMerged(): bool
    {
        return $this->action === 'merge';
    }
}
