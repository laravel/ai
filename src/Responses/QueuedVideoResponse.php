<?php

namespace Laravel\Ai\Responses;

use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * @mixin PendingDispatch
 */
class QueuedVideoResponse
{
    use Concerns\HasQueuedResponseCallbacks;

    public function __construct(public PendingDispatch $dispatchable) {}

    /**
     * Set the desired connection for the job.
     *
     * @param  \BackedEnum|string|null  $connection
     */
    public function onConnection($connection): static
    {
        $this->dispatchable->onConnection($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     *
     * @param  \BackedEnum|string|null  $queue
     */
    public function onQueue($queue): static
    {
        $this->dispatchable->onQueue($queue);

        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * @param  \UnitEnum|string  $group
     */
    public function onGroup($group): static
    {
        $this->dispatchable->onGroup($group);

        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * @param  callable|null  $deduplicator
     */
    public function withDeduplicator($deduplicator): static
    {
        $this->dispatchable->withDeduplicator($deduplicator);

        return $this;
    }

    /**
     * Set the desired connection for the chain.
     *
     * @param  \BackedEnum|string|null  $connection
     */
    public function allOnConnection($connection): static
    {
        $this->dispatchable->allOnConnection($connection);

        return $this;
    }

    /**
     * Set the desired queue for the chain.
     *
     * @param  \BackedEnum|string|null  $queue
     */
    public function allOnQueue($queue): static
    {
        $this->dispatchable->allOnQueue($queue);

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     */
    public function delay($delay): static
    {
        $this->dispatchable->delay($delay);

        return $this;
    }

    /**
     * Set the delay for the job to zero seconds.
     */
    public function withoutDelay(): static
    {
        $this->dispatchable->withoutDelay();

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after all database transactions have committed.
     */
    public function afterCommit(): static
    {
        $this->dispatchable->afterCommit();

        return $this;
    }

    /**
     * Indicate that the job should not wait until database transactions have committed before dispatching.
     */
    public function beforeCommit(): static
    {
        $this->dispatchable->beforeCommit();

        return $this;
    }

    /**
     * Set the jobs that should run if this job is successful.
     */
    public function chain($chain): static
    {
        $this->dispatchable->chain($chain);

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after the response is sent to the browser.
     */
    public function afterResponse(bool $afterResponse = true): static
    {
        $this->dispatchable->afterResponse($afterResponse);

        return $this;
    }

    /**
     * Proxy missing method calls to the pending dispatch instance.
     */
    public function __call(string $method, array $arguments)
    {
        return $this->dispatchable->{$method}(...$arguments);
    }
}
