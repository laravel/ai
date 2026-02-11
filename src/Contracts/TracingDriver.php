<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\Events\Dispatcher;

interface TracingDriver
{
    /**
     * Register event listeners for the given agent invocation.
     */
    public function registerListeners(string $invocationId, Agent $agent, Dispatcher $events): void;
}
