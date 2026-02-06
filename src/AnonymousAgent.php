<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasSubAgents;
use Laravel\Ai\Contracts\HasTools;

class AnonymousAgent implements Agent, Conversational, HasSubAgents, HasTools
{
    use Promptable;

    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        public iterable $agents = [],
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function subAgents(): array
    {
        return is_array($this->agents)
            ? $this->agents
            : iterator_to_array($this->agents, false);
    }
}
