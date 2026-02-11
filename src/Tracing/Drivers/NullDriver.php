<?php

namespace Laravel\Ai\Tracing\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\TracingDriver;

class NullDriver implements TracingDriver
{
    /**
     * Register event listeners for the given agent invocation.
     */
    public function registerListeners(string $invocationId, Agent $agent, Dispatcher $events): void
    {
        //
    }
}
