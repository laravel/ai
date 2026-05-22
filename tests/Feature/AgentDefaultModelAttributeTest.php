<?php

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Providers\FakeStreamingProvider;

function fakeProviderForModelResolution(): FakeStreamingProvider
{
    return new class([], app('events'), fn () => null) extends FakeStreamingProvider
    {
        public function defaultTextModel(): string
        {
            return 'default-model';
        }

        public function cheapestTextModel(): string
        {
            return 'cheapest-model';
        }

        public function smartestTextModel(): string
        {
            return 'smartest-model';
        }
    };
}

function resolveDefaultModel(Agent $agent, FakeStreamingProvider $provider): string
{
    $method = new ReflectionMethod($agent, 'getDefaultModelFor');
    $method->setAccessible(true);

    return $method->invoke($agent, $provider);
}

#[UseSmartestModel]
class SmartestModelAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[UseCheapestModel]
class CheapestModelAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[UseSmartestModel]
#[UseCheapestModel]
class BothModelAttributesAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

class NoModelAttributeAgentFixture implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

test('UseSmartestModel attribute resolves to the providers smartest text model', function () {
    $model = resolveDefaultModel(new SmartestModelAgentFixture, fakeProviderForModelResolution());

    expect($model)->toBe('smartest-model');
});

test('UseCheapestModel attribute resolves to the providers cheapest text model', function () {
    $model = resolveDefaultModel(new CheapestModelAgentFixture, fakeProviderForModelResolution());

    expect($model)->toBe('cheapest-model');
});

test('UseSmartestModel takes precedence over UseCheapestModel when both are present', function () {
    $model = resolveDefaultModel(new BothModelAttributesAgentFixture, fakeProviderForModelResolution());

    expect($model)->toBe('smartest-model');
});

test('falls back to defaultTextModel when neither attribute is present', function () {
    $model = resolveDefaultModel(new NoModelAttributeAgentFixture, fakeProviderForModelResolution());

    expect($model)->toBe('default-model');
});
