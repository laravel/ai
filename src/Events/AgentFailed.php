<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class AgentFailed
{
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
        public Throwable $exception,
    ) {}
}
