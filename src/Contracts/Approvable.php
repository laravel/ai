<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

interface Approvable
{
    /**
     * Configure the tool to require approval before execution.
     */
    public function requireApproval(?string $reason = null): static;

    /**
     * Configure the tool to execute without approval.
     */
    public function withoutApproval(): static;

    /**
     * Determine whether the tool should request approval for the given request.
     */
    public function shouldRequestApproval(Request $request): ?Approval;
}
