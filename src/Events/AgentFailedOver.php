<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;

class AgentFailedOver
{
    public function __construct(
        public Agent $agent,
        public Provider $provider,
        public string $model,
        public FailoverableException $exception) {}
}
