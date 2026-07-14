<?php

namespace Laravel\Ai\Exceptions;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;

class ApprovalNotResumableException extends AiException
{
    public static function make(): self
    {
        return new self('Tool approval requires a conversational agent so pending tool calls can be resumed from history.');
    }

    /**
     * Throw when a paused approval cannot be resumed from persisted history.
     */
    public static function throwUnlessResumable(Agent $agent): void
    {
        if (! Approval::resumableFor($agent)) {
            throw static::make();
        }
    }
}
