<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Tools\RandomNumberGenerator;

#[ToolChoice(ToolChoice::required)]
class ThinkingToolChoiceAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 10_000,
            ],
        ];
    }
}
