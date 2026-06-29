<?php

namespace Laravel\Ai\Responses;

use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * @mixin PendingDispatch
 */
class QueuedImageResponse
{
    use Concerns\ForwardsToPendingDispatch;
    use Concerns\HasQueuedResponseCallbacks;

    public function __construct(public PendingDispatch $dispatchable) {}
}
