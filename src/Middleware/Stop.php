<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

class Stop
{
    /**
     * @param  Closure(PendingStep): bool|bool  $condition
     */
    public function __construct(protected Closure|bool $condition) {}

    /**
     * Stop the run before any step for which the given condition holds.
     *
     * @param  Closure(PendingStep): bool|bool  $condition
     */
    public static function when(Closure|bool $condition): self
    {
        return new self($condition);
    }

    /**
     * Stop the run before any step for which the given condition fails.
     *
     * @param  Closure(PendingStep): bool|bool  $condition
     */
    public static function unless(Closure|bool $condition): self
    {
        return new self(fn (PendingStep $step): bool => ! value($condition, $step));
    }

    /**
     * Handle the pending step.
     */
    public function handle(PendingStep $step, Closure $next): mixed
    {
        return value($this->condition, $step) ? StepResult::stop() : $next($step);
    }
}
