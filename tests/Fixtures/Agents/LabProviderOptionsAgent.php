<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class LabProviderOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, array<string, mixed>>  $options  The options keyed by provider name.
     */
    public function __construct(public array $options = [])
    {
        //
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->options[$provider instanceof Lab ? $provider->value : $provider] ?? [];
    }
}
