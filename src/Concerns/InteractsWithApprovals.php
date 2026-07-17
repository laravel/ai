<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

trait InteractsWithApprovals
{
    protected Approval|bool|null $approvalRequirement = null;

    /**
     * Indicate that the tool requires approval before execution.
     */
    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    /**
     * Indicate that the tool may execute without approval.
     */
    public function withoutApproval(): static
    {
        $this->approvalRequirement = false;

        return $this;
    }

    /**
     * Determine whether the tool should request approval for the given request.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $result = $this->approvalRequirement ?? $this->needsApproval($request);

        return match (true) {
            $result === false => null,
            $result === true => Approval::required(),
            default => $result,
        };
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): bool|Approval
    {
        return true;
    }
}
