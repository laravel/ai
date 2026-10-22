<?php

namespace Laravel\Ai\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\PendingApproval;
use Symfony\Component\HttpFoundation\Response;

class InvalidToolInputException extends ValidationException
{
    /**
     * @var Collection<int, PendingApproval>
     */
    public Collection $pendingApprovals;

    /**
     * Create an exception for the given errors, carrying the pauses the caller must answer again.
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public static function withPendingApprovals(array $errors, Collection $pendingApprovals): static
    {
        $exception = static::withMessages($errors);

        $exception->pendingApprovals = $pendingApprovals;

        return $exception;
    }

    /**
     * Render the exception as JSON for clients that re-render the pause, deferring to Laravel otherwise.
     */
    public function render(Request $request): ?Response
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
            'approvals' => $this->pendingApprovals->values()->toArray(),
        ], $this->status);
    }
}
