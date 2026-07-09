<?php

namespace Laravel\Ai\Exceptions;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

class ApprovalNotResumableException extends AiException
{
    public static function make(): self
    {
        return new self('Tool approval requires a conversational agent so pending tool calls can be resumed from history.');
    }

    /**
     * Determine whether a paused approval can be resumed from persisted history.
     */
    public static function resumable(Agent $agent): bool
    {
        if (! $agent instanceof Conversational) {
            return false;
        }

        if (! in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
            return true;
        }

        /** @var Agent&RemembersConversationsContract $agent */
        return $agent->hasConversationParticipant();
    }
}
