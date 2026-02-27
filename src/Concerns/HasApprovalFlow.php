<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Events\ToolApproved;
use Laravel\Ai\Events\ToolRejected;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\PendingToolCall;
use Laravel\Ai\Tools\Request;

trait HasApprovalFlow
{
    /**
     * Approve a pending tool call, execute the tool, and re-prompt the agent with the result.
     */
    public function approve(PendingToolCall $pendingToolCall): AgentResponse
    {
        event(new ToolApproved($this, $pendingToolCall));

        $result = $pendingToolCall->tool->handle(new Request($pendingToolCall->arguments));

        return $this->prompt(
            "The tool '{$pendingToolCall->toolName()}' was approved and executed. Result: {$result}"
        );
    }

    /**
     * Reject a pending tool call.
     */
    public function reject(PendingToolCall $pendingToolCall, ?string $reason = null): AgentResponse
    {
        event(new ToolRejected($this, $pendingToolCall, $reason));

        $message = $reason
            ? "The tool '{$pendingToolCall->toolName()}' was rejected. Reason: {$reason}"
            : "The tool '{$pendingToolCall->toolName()}' was rejected by the user.";

        return $this->prompt($message);
    }
}
