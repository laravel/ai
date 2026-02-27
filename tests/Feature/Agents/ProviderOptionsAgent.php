<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class ProviderOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Anthropic => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ],
            ],
            default => [],
        };
    }
}
