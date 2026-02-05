<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\FakePendingDispatch;
use Laravel\Ai\PendingDispatch;

class QueuedTranslationResponse
{
    public function __construct(
        public FakePendingDispatch|PendingDispatch $pendingDispatch,
    ) {}
}
