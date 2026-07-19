<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasHeaders;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class HeadersAgent implements Agent, HasHeaders
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function headers(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::OpenAI => [
                'X-Custom-Header' => 'openai-value',
                'X-Request-Source' => 'laravel-ai',
            ],
            Lab::Anthropic => [
                'X-Custom-Header' => 'anthropic-value',
            ],
            Lab::Groq => [
                'X-Custom-Header' => 'groq-value',
            ],
            Lab::Bedrock => [
                'X-Custom-Header' => 'bedrock-value',
            ],
            default => [],
        };
    }
}
