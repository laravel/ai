<?php

namespace Laravel\Ai\Responses;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Traversable;

class FineTuningJobListResponse implements Arrayable, Countable, IteratorAggregate
{
    /**
     * Create a new fine-tuning job list response instance.
     *
     * @param  array<FineTuningJobResponse>  $jobs
     */
    public function __construct(
        public array $jobs,
        public bool $hasMore = false,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'jobs' => array_map(fn ($job) => (array) $job, $this->jobs),
            'has_more' => $this->hasMore,
        ];
    }

    /**
     * Get the number of jobs in the response.
     */
    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * Get an iterator for the jobs.
     */
    public function getIterator(): Traversable
    {
        foreach ($this->jobs as $job) {
            yield $job;
        }
    }
}
