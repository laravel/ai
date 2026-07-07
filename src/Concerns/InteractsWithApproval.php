<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

trait InteractsWithApproval
{
    protected ?Approval $approvalRequirement = null;

    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvalRequirement = Approval::notRequired();

        return $this;
    }

    public function shouldRequestApproval(Request $request): Approval
    {
        return $this->approvalRequirement ?? $this->needsApproval($request);
    }

    protected function needsApproval(Request $request): Approval
    {
        return Approval::required();
    }
}
