<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class RememberingSubAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        messages as storedMessages;
    }

    /**
     * The number of stored messages the agent was given on each of its prompts.
     *
     * @var list<int>
     */
    public array $historySizes = [];

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You complete the task you are given and report back concisely.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        $messages = [...$this->storedMessages()];

        $this->historySizes[] = count($messages);

        return $messages;
    }
}
