<?php

namespace Laravel\Ai\Contracts;

use Closure;
use Laravel\Ai\Skills\Skill;

interface HasSkills
{
    /**
     * Get the skills available to the agent.
     *
     * @return iterable<Closure|Skill|string>
     */
    public function skills(): iterable;
}
