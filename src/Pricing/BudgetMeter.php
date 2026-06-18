<?php

namespace Laravel\Ai\Pricing;

use Laravel\Ai\Responses\Data\Cost;

class BudgetMeter
{
    /**
     * Dollars spent within the current process, keyed by bucket (e.g. agent class).
     *
     * @var array<string, float>
     */
    protected array $spent = [];

    /**
     * Record spend against the given bucket.
     */
    public function record(string $key, Cost $cost): void
    {
        $this->spent[$key] = ($this->spent[$key] ?? 0.0) + $cost->total();
    }

    /**
     * Get the amount spent against the given bucket.
     */
    public function spent(string $key): float
    {
        return $this->spent[$key] ?? 0.0;
    }

    /**
     * Get the total amount spent across every bucket.
     */
    public function total(): float
    {
        return array_sum($this->spent);
    }

    /**
     * Reset spend for a single bucket, or all buckets when none is given.
     */
    public function reset(?string $key = null): void
    {
        if ($key === null) {
            $this->spent = [];

            return;
        }

        unset($this->spent[$key]);
    }
}
