<?php

namespace Tests\Fixtures\Agents;

class AlwaysRememberedAgent extends RememberingAssistantAgent
{
    /**
     * Determine if the agent is remembered on behalf of a participant.
     */
    public function hasConversationParticipant(): bool
    {
        return true;
    }
}
