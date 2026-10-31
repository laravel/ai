<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

class RememberingStructuredApprovableAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that always uses the available tool to generate a number.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->required(),
        ];
    }
}
