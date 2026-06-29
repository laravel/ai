<?php

namespace Laravel\Ai\Responses;

use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * @mixin PendingDispatch
 */
class QueuedVideoResponse
{
    use Concerns\ForwardsToPendingDispatch;
    use Concerns\HasQueuedResponseCallbacks;

    public function __construct(public PendingDispatch $dispatchable) {}
}
