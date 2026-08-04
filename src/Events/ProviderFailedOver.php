<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;

class ProviderFailedOver
{
    public function __construct(
        public ?string $invocationId,
        public Provider $provider,
        public string $model,
        public FailoverableException $exception) {}
}
