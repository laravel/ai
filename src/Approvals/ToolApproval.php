<?php

namespace Laravel\Ai\Approvals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ToolApproval
{
    /**
     * @param  array<string, Approval>  $decisions
     */
    public function __construct(public array $decisions) {}

    /**
     * @param  array<string, Approval|bool>  $decisions
     */
    public static function from(array $decisions): self
    {
        $normalized = [];

        foreach ($decisions as $id => $decision) {
            if ($decision === true) {
                $normalized[$id] = Approval::approve();

                continue;
            }

            if ($decision === false) {
                $normalized[$id] = Approval::reject();

                continue;
            }

            if (! $decision instanceof Approval) {
                throw new InvalidArgumentException('Tool approval decisions must be Approval instances or booleans.');
            }

            $normalized[$id] = $decision;
        }

        return new self($normalized);
    }

    public static function fromRequest(Request $request): ?self
    {
        if (! $request->has('decisions')) {
            return null;
        }

        $payload = Validator::make($request->all(), [
            'decisions' => ['required', 'array'],
            'decisions.*.id' => ['required', 'string'],
            'decisions.*.action' => ['required', 'string', 'in:approve,reject,edit,reason'],
            'decisions.*.arguments' => ['required_if:decisions.*.action,edit', 'array'],
            'decisions.*.result' => ['required_if:decisions.*.action,reason', 'prohibited_unless:decisions.*.action,reason', 'string'],
        ])->validate();

        $decisions = [];

        foreach ($payload['decisions'] as $decision) {
            $decisions[$decision['id']] = match ($decision['action']) {
                'approve' => Approval::approve(),
                'reject' => Approval::reject(),
                'reason' => Approval::reject($decision['result']),
                'edit' => Approval::edit($decision['arguments']),
            };
        }

        return new self($decisions);
    }
}
