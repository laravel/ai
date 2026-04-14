<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class ProviderOptionsWithToolsAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [
            new FixedNumberGenerator,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Anthropic => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ],
            ],
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
            ],
            Lab::xAI => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Groq => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Mistral => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Ollama => [
                'top_k' => 40,
            ],
            Lab::OpenRouter => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Gemini => [
                'thinkingConfig' => [
                    'thinkingBudget' => 10000,
                ],
            ],
            default => [],
        };
    }
}
