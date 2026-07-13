<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Approvals\ApprovalRequirement;
use Laravel\Ai\Tools\Request;

trait InteractsWithApproval
{
    protected ?ApprovalRequirement $approvalRequirement = null;

    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = ApprovalRequirement::required($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvalRequirement = ApprovalRequirement::notRequired();

        return $this;
    }

    public function shouldRequestApproval(Request $request): ApprovalRequirement
    {
        return $this->approvalRequirement ?? $this->needsApproval($request);
    }

    protected function needsApproval(Request $request): ApprovalRequirement
    {
        return ApprovalRequirement::required();
    }
}
