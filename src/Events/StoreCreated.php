<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\CreatedStoreResponse;

class StoreCreated
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public CreatedStoreResponse $response,
    ) {}
}
