<?php

namespace Laravel\Ai\Responses\Concerns;

use Closure;

trait HasQueuedResponseCallbacks
{
    /**
     * Add a callback to be executed after the agent is invoked.
     */
    public function then(Closure $callback): self
    {
        $this->dispatchable->getJob()->then($callback);

        return $this;
    }

    /**
     * Add a callback to be executed if the agent fails.
     */
    public function catch(Closure $callback): self
    {
        $this->dispatchable->getJob()->catch($callback);

        return $this;
    }
}
