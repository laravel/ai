<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Providers\Provider;

class CreatingStore
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
    ) {}
}
