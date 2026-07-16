<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Gateway\PendingStep;

abstract class Middleware
{
    /**
     * Handle a single generation step.
     */
    abstract public function handle(PendingStep $step, Closure $next);
}
