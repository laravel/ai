<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Provider;

class OnDemandProviderAgent extends AssistantAgent
{
    public function __construct(public string $key) {}

    public function provider(): Provider
    {
        return Ai::build(['driver' => 'anthropic', 'key' => $this->key]);
    }
}
