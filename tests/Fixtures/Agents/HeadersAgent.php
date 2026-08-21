<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HeadersAgent implements Agent, HasProviderOptions
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
            Lab::OpenAI => ['ai_sdk_extra_headers' => [
                'X-Custom-Header' => 'openai-value',
                'X-Request-Source' => 'laravel-ai',
            ]],
            Lab::Groq => ['ai_sdk_extra_headers' => ['X-Custom-Header' => 'groq-value']],
            default => [],
        };
    }
}
