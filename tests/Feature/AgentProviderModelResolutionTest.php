<?php

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

function resolveProvidersAndModels(Agent $agent, mixed $provider = null, ?string $model = null): array
{
    $method = new ReflectionMethod($agent, 'getProvidersAndModels');
    $method->setAccessible(true);

    return $method->invoke($agent, $provider, $model);
}

#[Provider('anthropic')]
#[Model('attribute-model')]
class ProviderAttributesAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[Provider('anthropic')]
#[Model('attribute-model')]
class ProviderMethodsAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return 'method-model';
    }
}

#[Provider('anthropic')]
#[Model('attribute-model')]
class ProviderMethodReturnsNullAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function provider(): ?string
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }
}

class NoProviderConfigAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

test('explicit provider and model arguments override both method and attribute', function () {
    expect(resolveProvidersAndModels(new ProviderMethodsAgentFixture, 'cohere', 'explicit-model'))
        ->toBe(['cohere' => 'explicit-model']);
});

test('provider attribute is used when no explicit argument and no method', function () {
    expect(resolveProvidersAndModels(new ProviderAttributesAgentFixture))
        ->toBe(['anthropic' => 'attribute-model']);
});

test('provider and model methods take precedence over attributes', function () {
    expect(resolveProvidersAndModels(new ProviderMethodsAgentFixture))
        ->toBe(['openai' => 'method-model']);
});

test('a provider method returning null short-circuits the attribute lookup and falls back to config', function () {
    config(['ai.default' => 'gemini']);

    expect(resolveProvidersAndModels(new ProviderMethodReturnsNullAgentFixture))
        ->toBe(['gemini' => null]);
});

test('config ai.default is used when neither method nor attribute provides a provider', function () {
    config(['ai.default' => 'openai']);

    expect(resolveProvidersAndModels(new NoProviderConfigAgentFixture))
        ->toBe(['openai' => null]);
});

test('array failover provider skips model resolution from method or attribute', function () {
    expect(resolveProvidersAndModels(
        new ProviderMethodsAgentFixture,
        ['anthropic' => 'claude-opus-4-7', 'openai' => null],
    ))->toBe(['anthropic' => 'claude-opus-4-7', 'openai' => null]);
});
