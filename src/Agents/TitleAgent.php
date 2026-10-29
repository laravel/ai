<?php

namespace Laravel\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[UseCheapestModel]
final class TitleAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.';
    }
}
