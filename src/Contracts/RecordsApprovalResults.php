<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Responses\Data\ToolResult;

interface RecordsApprovalResults
{
    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param  array<int, ToolResult>  $toolResults
     */
    public function recordApprovalResults(string $conversationId, string|int|null $participantId, array $toolResults): void;
}
