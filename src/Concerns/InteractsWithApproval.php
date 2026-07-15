<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

trait InteractsWithApproval
{
    protected bool|Approval|null $approvalOverride = null;

    public function requireApproval(?string $reason = null): static
    {
        $this->approvalOverride = Approval::required($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvalOverride = false;

        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        $result = $this->approvalOverride ?? $this->needsApproval($request);

        return match (true) {
            $result === false => null,
            $result === true => Approval::required(),
            default => $result,
        };
    }

    protected function needsApproval(Request $request): bool|Approval
    {
        return true;
    }
}
