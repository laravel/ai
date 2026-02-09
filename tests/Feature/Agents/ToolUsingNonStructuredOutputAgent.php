<?php

namespace Tests\Feature\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

class ToolUsingNonStructuredOutputAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(public bool $fixed = false, public bool $toolThrowsException = false) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates random numbers using the tool available to you. Always use the tool to get a cryptographically secure random number.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            $this->fixed
                ? new FixedNumberGenerator($this->toolThrowsException)
                : new RandomNumberGenerator($this->toolThrowsException),
        ];
    }
}
