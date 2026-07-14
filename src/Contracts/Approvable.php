<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

interface Approvable
{
    public function requireApproval(?string $reason = null): static;

    public function withoutApproval(): static;

    public function shouldRequestApproval(Request $request): ?Approval;
}
