<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\SubAgent;
use Laravel\Ai\Promptable;

class DeterministicSubAgent implements SubAgent
{
    use Promptable;

    public function name(): string
    {
        return 'deterministic_subagent';
    }

    public function instructions(): string
    {
        return 'You are a deterministic subagent.';
    }

    public function description(): string
    {
        return 'Handles delegated tasks deterministically for tests.';
    }
}
