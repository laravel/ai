<?php

namespace Laravel\Ai\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Symfony\Component\HttpFoundation\Response;

class ApprovalMismatchException extends AiException
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public function __construct(string $message, public Collection $pendingApprovals)
    {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        return response()->json([
            'message' => $this->getMessage(),
            'approvals' => $this->pendingApprovals->values()->toArray(),
        ], 409);
    }
}
