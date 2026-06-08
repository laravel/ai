<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

#[MaxSteps(10)]
class MultiStepToolAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}
