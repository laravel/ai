<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Exceptions\FailoverableException;

class AgentFailedOver extends ProviderFailedOver
{
    public function __construct(
        public Agent $agent,
        public Provider $provider,
        public string $model,
        public FailoverableException $exception) {}
}
