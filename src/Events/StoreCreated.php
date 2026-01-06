<?php

namespace Laravel\Ai\Events;

use DateInterval;
use Illuminate\Support\Collection;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Store;

class StoreCreated
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
        public Store $store,
    ) {}
}
