<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\AgentPrompt;

class InvokingAgent
{
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
    ) {}
}
