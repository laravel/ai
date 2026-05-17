<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Exceptions\FailoverableException;

class ProviderFailedOver
{
    public function __construct(
        public Provider $provider,
        public string $model,
        public FailoverableException $exception) {}
}
