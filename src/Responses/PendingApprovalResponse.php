<?php

namespace Laravel\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\PendingToolCall;
use Laravel\Ai\Responses\Data\Usage;

class PendingApprovalResponse extends AgentResponse
{
    /**
     * The pending tool calls requiring approval.
     *
     * @var \Illuminate\Support\Collection<int, PendingToolCall>
     */
    public Collection $pendingToolCalls;

    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        parent::__construct($invocationId, $text, $usage, $meta);

        $this->pendingToolCalls = new Collection;
    }

    /**
     * Add a pending tool call to the response.
     */
    public function addPendingToolCall(PendingToolCall $pendingToolCall): self
    {
        $this->pendingToolCalls->push($pendingToolCall);

        return $this;
    }

    /**
     * Determine if the response is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->pendingToolCalls->isNotEmpty();
    }

    /**
     * Determine if the response requires approval.
     */
    public function requiresApproval(): bool
    {
        return $this->isPendingApproval();
    }
}
