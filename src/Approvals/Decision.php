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

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
        return new self('reject', result: blank($result) ? null : $result);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    /**
     * A blanket approval for every pending tool call.
     *
     * @return array<string, Decision>
     */
    public static function approveAll(): array
    {
        return ['*' => self::approve()];
    }

    /**
     * A blanket rejection for every pending tool call.
     *
     * @return array<string, Decision>
     */
    public static function rejectAll(): array
    {
        return ['*' => self::reject()];
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

            $normalized[$id] = $decision;
        }

        return $normalized;
    }

    public function isApproved(): bool
    {
        return $this->action === 'approve';
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
