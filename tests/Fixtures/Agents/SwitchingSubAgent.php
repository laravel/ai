<?php

namespace Tests\Fixtures\Agents;

use Closure;

/**
 * A remembering sub-agent that changes its conversation or participant once it runs.
 */
class SwitchingSubAgent extends RememberingSubAgent
{
    /**
     * The change the sub-agent makes to itself on its next run.
     *
     * @var (Closure(self): mixed)|null
     */
    public ?Closure $whileRunning = null;

    public function instructions(): string
    {
        if ($this->whileRunning !== null) {
            ($this->whileRunning)($this);

            $this->whileRunning = null;
        }

        return parent::instructions();
    }
}
