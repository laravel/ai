<?php

namespace Laravel\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
final class SummarizeConversationAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'Summarize the given conversation for use as the memory of an ongoing chat. Preserve decisions, facts, identifiers, and unresolved questions. Drop pleasantries. Respond with only the summary and nothing else.';
    }
}
