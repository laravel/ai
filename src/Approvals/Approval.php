<?php

namespace Laravel\Ai\Approvals;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

class Approval
{
    public function __construct(public ?string $reason = null)
    {
        //
    }

    public static function required(?string $reason = null): self
    {
        return new self($reason);
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
