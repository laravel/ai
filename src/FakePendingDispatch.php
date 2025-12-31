<?php

namespace Laravel\Ai;

use Illuminate\Foundation\Bus\PendingDispatch;

class FakePendingDispatch extends PendingDispatch
{
    /**
     * Create a new fake pending job dispatch.
     */
    public function __construct() {}

    /**
     * Set the desired connection for the job.
     *
     * @param  \BackedEnum|string|null  $connection
     * @return $this
     */
    public function onConnection($connection)
    {
        return $this;
    }

    /**
     * Set the desired queue for the job.
     *
     * @param  \BackedEnum|string|null  $queue
     * @return $this
     */
    public function onQueue($queue)
    {
        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     *
     * @param  \UnitEnum|string  $group
     * @return $this
     */
    public function onGroup($group)
    {
        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * This feature is only supported by some queues, such as Amazon SQS FIFO.
     *
     * @param  callable|null  $deduplicator
     * @return $this
     */
    public function withDeduplicator($deduplicator)
    {
        return $this;
    }

    /**
     * Set the desired connection for the chain.
     *
     * @param  \BackedEnum|string|null  $connection
     * @return $this
     */
    public function allOnConnection($connection)
    {
        return $this;
    }

    /**
     * Set the desired queue for the chain.
     *
     * @param  \BackedEnum|string|null  $queue
     * @return $this
     */
    public function allOnQueue($queue)
    {
        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @return $this
     */
    public function delay($delay)
    {
        return $this;
    }

    /**
     * Set the delay for the job to zero seconds.
     *
     * @return $this
     */
    public function withoutDelay()
    {
        return $this;
    }

    /**
     * Indicate that the job should be dispatched after all database transactions have committed.
     *
     * @return $this
     */
    public function afterCommit()
    {
        return $this;
    }

    /**
     * Indicate that the job should not wait until database transactions have been committed before dispatching.
     *
     * @return $this
     */
    public function beforeCommit()
    {
        return $this;
    }

    /**
     * Set the jobs that should run if this job is successful.
     *
     * @param  array  $chain
     * @return $this
     */
    public function chain($chain)
    {
        return $this;
    }

    /**
     * Indicate that the job should be dispatched after the response is sent to the browser.
     *
     * @param  bool  $afterResponse
     * @return $this
     */
    public function afterResponse($afterResponse = true)
    {
        return $this;
    }

    /**
     * Determine if the job should be dispatched.
     *
     * @return bool
     */
    protected function shouldDispatch()
    {
        return true;
    }

    /**
     * Get the underlying job instance.
     *
     * @return mixed
     */
    public function getJob()
    {
        return new class {};
    }

    /**
     * Dynamically proxy methods to the underlying job.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return $this
     */
    public function __call($method, $parameters)
    {
        return $this;
    }

    /**
     * Handle the object's destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        //
    }
}
