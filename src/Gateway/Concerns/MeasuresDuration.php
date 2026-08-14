<?php

namespace Laravel\Ai\Gateway\Concerns;

trait MeasuresDuration
{
    /**
     * Get the milliseconds elapsed since the given monotonic nanosecond reading.
     */
    protected function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e6;
    }
}
