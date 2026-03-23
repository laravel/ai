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
     * Proxy missing method calls to the pending dispatch instance.
     */
    public function __call(string $method, array $arguments)
    {
        return $this->dispatchable->{$method}(...$arguments);
    }
}
