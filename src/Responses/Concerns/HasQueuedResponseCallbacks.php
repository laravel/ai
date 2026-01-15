<?php

namespace Laravel\Ai\Responses\Concerns;

use Closure;
use Laravel\Ai\FakePendingDispatch;

trait HasQueuedResponseCallbacks
{
    /**
     * Add a callback to be executed after the agent is invoked.
     */
    public function then(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $this->dispatchable->getJob()->then($callback);
        }

        return $this;
    }

    /**
     * Add a callback to be executed if the agent fails.
     */
    public function catch(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $this->dispatchable->getJob()->catch($callback);
        }

        return $this;
    }
}
