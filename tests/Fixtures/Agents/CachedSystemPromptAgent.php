<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class CachedSystemPromptAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant with a large knowledge base.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::Anthropic, 'anthropic' => [
                'system_prompt_cache_type' => 'ephemeral',
            ],
            default => [],
        };
    }
}
