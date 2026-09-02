<?php

namespace Laravel\Ai\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Symfony\Component\HttpFoundation\Response;

class ApprovalMismatchException extends AiException
{
    /**
     * Create a new approval mismatch exception.
     *
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public function __construct(string $message, public Collection $pendingApprovals)
    {
        parent::__construct($message);
    }

    /**
     * Determine if the run is still paused on approvals that may be decided again.
     */
    public function isRecoverable(): bool
    {
        return $this->pendingApprovals->isNotEmpty();
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        return response()->json([
            'message' => $this->getMessage(),
            'approvals' => $this->pendingApprovals->values()->toArray(),
        ], 409);
    }
}
