<?php

namespace Tests\Fixtures\Agents;

/**
 * A remembering sub-agent switched to incognito, which is not remembered although it reports a participant.
 */
class IncognitoSubAgent extends RememberingSubAgent
{
    public function hasConversationParticipant(): bool
    {
        return false;
    }

    public function conversationParticipant(): ?object
    {
        return $this->conversationUser ?? (object) ['id' => 9];
    }
}
