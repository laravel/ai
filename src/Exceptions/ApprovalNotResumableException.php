<?php

namespace Laravel\Ai\Exceptions;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

class ApprovalNotResumableException extends AiException
{
    public static function make(): self
    {
        return new self('Tool approval requires a conversational agent so pending tool calls can be resumed from history.');
    }

    /**
     * Throw before a run begins when a non-resumable agent carries a tool that could pause for approval.
     *
     * @param  array<int, object>  $tools
     */
    public static function throwUnlessResumable(Agent $agent, array $tools): void
    {
        if (static::resumableFor($agent)) {
            return;
        }

        foreach ($tools as $tool) {
            if ($tool instanceof Approvable && $tool->mayRequireApproval()) {
                throw static::make();
            }
        }
    }

    /**
     * Determine whether the given agent can resume a paused approval from persisted history.
     */
    public static function resumableFor(Agent $agent): bool
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
