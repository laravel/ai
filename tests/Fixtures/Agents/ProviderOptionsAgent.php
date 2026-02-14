<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

class ProviderOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function providerOptions(): array
    {
        return [
            'reasoning' => [
                'effort' => 'high',
            ],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ];
    }
}
