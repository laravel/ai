<?php

namespace Tests\Fixtures\Agents;

/**
 * A remembering sub-agent that only picks its participant once it runs.
 */
class LateParticipantSubAgent extends RememberingSubAgent
{
    public function instructions(): string
    {
        if ($this->conversationParticipant() === null) {
            $this->forUser((object) ['id' => 7]);
        }

        return parent::instructions();
    }
}
