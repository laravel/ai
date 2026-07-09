<?php

namespace Laravel\Ai\Approvals;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    public static function fromRequest(Request $request): ?self
    {
        if (! $request->has('decisions')) {
            return null;
        }

        $payload = Validator::make($request->all(), [
            'decisions' => ['required', 'array'],
            'decisions.*' => ['array', function (string $attribute, mixed $value, Closure $fail) {
                if (($value['id'] ?? null) === '*' && ($value['action'] ?? null) === 'edit') {
                    $fail('The wildcard decision may not use the edit action.');
                }
            }],
            'decisions.*.id' => ['required', 'string'],
            'decisions.*.action' => ['required', 'string', 'in:approve,reject,edit,reason'],
            'decisions.*.arguments' => ['required_if:decisions.*.action,edit', 'array'],
            'decisions.*.result' => ['required_if:decisions.*.action,reason', 'prohibited_unless:decisions.*.action,reason', 'string'],
        ])->validate();

        return static::from(collect($payload['decisions'])->mapWithKeys(fn (array $decision) => [
            $decision['id'] => match ($decision['action']) {
                'approve' => Approval::approve(),
                'reject' => Approval::reject(),
                'reason' => Approval::reject($decision['result']),
                'edit' => Approval::edit($decision['arguments']),
            },
        ])->all());
    }
}
