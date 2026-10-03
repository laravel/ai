<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Sandboxes\Sandbox;

class SandboxResolved
{
    public function __construct(
        public Agent $agent,
        public string $id,
        public Sandbox $sandbox,
    ) {}
}
