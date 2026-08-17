<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Gateway\PendingStep;

abstract class Middleware
{
    /**
     * Handle a single generation step.
     *
     * `$next` runs the step and returns its `StepResponse`, or a `Generator` of stream events when `$step->streaming` is true.
     */
    abstract public function handle(PendingStep $step, Closure $next);
}
