<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\FailoverModelAttributeAgent;

test('numeric provider list uses passed model as default for each provider', function () {
    expect(Provider::formatProviderAndModelList(['primary', 'backup'], 'gpt-4.1-mini'))
        ->toBe(['primary' => 'gpt-4.1-mini', 'backup' => 'gpt-4.1-mini']);
});

test('numeric provider list retains null model when none given', function () {
    expect(Provider::formatProviderAndModelList(['primary', 'backup']))
        ->toBe(['primary' => null, 'backup' => null]);
});

test('associative provider list preserves per-provider models', function () {
    expect(Provider::formatProviderAndModelList(['openai' => 'gpt-4.1-mini', 'mistral' => 'mistral-large-latest']))
        ->toBe(['openai' => 'gpt-4.1-mini', 'mistral' => 'mistral-large-latest']);
});

test('Lab enum provider list uses passed model as default', function () {
    expect(Provider::formatProviderAndModelList([Lab::OpenAI, Lab::Azure], 'gpt-4.1-mini'))
        ->toBe(['openai' => 'gpt-4.1-mini', 'azure' => 'gpt-4.1-mini']);
});

test('provider attribute list uses model attribute when prompting', function () {
    config([
        'ai.providers.primary' => ['driver' => 'openai', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'openai', 'key' => 'test-key'],
    ]);

    FailoverModelAttributeAgent::fake();

    (new FailoverModelAttributeAgent)->prompt('Hello');

    FailoverModelAttributeAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->provider->name() === 'primary'
            && $prompt->model === 'gpt-4.1-mini';
    });
});

test('explicit prompt model is used for provider lists', function () {
    config([
        'ai.providers.primary' => ['driver' => 'openai', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'openai', 'key' => 'test-key'],
    ]);

    AssistantAgent::fake();

    (new AssistantAgent)->prompt(
        'Hello',
        provider: ['primary', 'backup'],
        model: 'gpt-4.1-mini',
    );

    AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->provider->name() === 'primary'
            && $prompt->model === 'gpt-4.1-mini';
    });
});
