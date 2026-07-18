<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

class StatelessApprovableAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You generate numbers on request.';
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }
}
