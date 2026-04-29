<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMcpServers;
use Laravel\Ai\Contracts\HasTools;

class AnonymousAgent implements Agent, Conversational, HasMcpServers, HasTools
{
    use Promptable;

    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        public array $mcpServers = [],
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

    public function mcpServers(): array
    {
        return $this->mcpServers;
    }
}
