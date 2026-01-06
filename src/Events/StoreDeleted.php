<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Providers\Provider;

class StoreDeleted
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
    ) {}
}
