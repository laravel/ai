<?php

use Laravel\Ai\Attributes\ServiceTier;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Bedrock\ServiceTier as BedrockServiceTier;
use Laravel\Ai\Enums\OpenAi\ServiceTier as OpenAiServiceTier;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;

it('resolves the service tier from a string returned by the agent method', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }

        public function serviceTier(): string
        {
            return 'priority';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBe('priority');
});

it('normalizes a provider enum returned by the agent method to its string value', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }

        public function serviceTier(): OpenAiServiceTier
        {
            return OpenAiServiceTier::Flex;
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBe('flex');
});

it('resolves the service tier from a string attribute', function () {
    $agent = new #[ServiceTier('priority')] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBe('priority');
});

it('normalizes a provider enum passed to the attribute', function () {
    $agent = new #[ServiceTier(BedrockServiceTier::Reserved)] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBe('reserved');
});

it('prefers the agent method over the attribute', function () {
    $agent = new #[ServiceTier('flex')] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }

        public function serviceTier(): string
        {
            return 'priority';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBe('priority');
});

it('treats an empty string service tier as unset', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }

        public function serviceTier(): string
        {
            return '';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBeNull();
});

it('is null when the agent declares no service tier', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->serviceTier)->toBeNull();
});
