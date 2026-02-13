<?php

namespace Laravel\Ai\Jobs\Concerns;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

trait InvokesQueuedResponseCallbacks
{
    protected array $thenCallbacks = [];

    protected array $catchCallbacks = [];

    /**
     * Invoke the given Closure then invoke the "then" callbacks.
     */
    protected function withCallbacks(Closure $action): mixed
    {
        $response = $action();

        foreach ($this->thenCallbacks as $callback) {
            $callback($response);
        }

        return $response;
    }

    /**
     * Add a callback to be executed after the agent is invoked.
     */
    public function then(Closure $callback): self
    {
        $this->thenCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Add a callback to be executed if the job fails.
     */
    public function catch(Closure $callback): self
    {
        $this->catchCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed(\Throwable $e): void
    {
        foreach ($this->catchCallbacks as $callback) {
            $callback($e);
        }
    }
}
