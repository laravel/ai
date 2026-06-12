<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;

class ProviderOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenRouter => ['reasoning' => ['effort' => 'medium']],
            Lab::Anthropic => ['thinking' => ['type' => 'enabled']],
            default => [],
        };
    }
}

class NoProviderOptionsAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

it('normalizes a string driver name to a Lab enum so the documented match idiom works', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    expect($options->providerOptions('openrouter'))
        ->toBe(['reasoning' => ['effort' => 'medium']]);
});

it('accepts a Lab enum directly without re-normalizing', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    expect($options->providerOptions(Lab::Anthropic))
        ->toBe(['thinking' => ['type' => 'enabled']]);
});

it('passes the raw string through when it does not match any Lab case', function () {
    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['received' => $provider];
        }
    };

    $options = TextGenerationOptions::forAgent($agent);

    expect($options->providerOptions('unknown-driver'))
        ->toBe(['received' => 'unknown-driver']);
});

it('returns null when the agent does not implement HasProviderOptions', function () {
    $options = TextGenerationOptions::forAgent(new NoProviderOptionsAgent);

    expect($options->providerOptions('openai'))->toBeNull();
});
