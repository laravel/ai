<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;

trait ObservesSteps
{
    protected Closure $startingStepCallback;

    protected Closure $stepCompletedCallback;

    protected Closure $stepFailedCallback;

    /**
     * Specify callbacks that should be invoked as generation steps start / complete / fail.
     */
    public function onStep(Closure $starting, Closure $completed, Closure $failed): self
    {
        $this->startingStepCallback = $starting;
        $this->stepCompletedCallback = $completed;
        $this->stepFailedCallback = $failed;

        return $this;
    }

    /**
     * Snapshot the current callbacks for the duration of a single generation run.
     *
     * Nested runs, such as a sub-agent invoked as a tool, replace the callbacks on the shared
     * loop instance, so each run holds the callbacks it started with rather than reading them back.
     *
     * @return array{starting: Closure, completed: Closure, failed: Closure}
     */
    protected function stepCallbacks(): array
    {
        $this->startingStepCallback ??= fn (): true => true;
        $this->stepCompletedCallback ??= fn (): true => true;
        $this->stepFailedCallback ??= fn (): true => true;

        return [
            'starting' => $this->startingStepCallback,
            'completed' => $this->stepCompletedCallback,
            'failed' => $this->stepFailedCallback,
        ];
    }
}
