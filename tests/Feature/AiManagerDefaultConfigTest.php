<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;

test('string default works', function () {
    config(['ai.default' => 'openai']);

    expect(Ai::textProvider())->toBeInstanceOf(OpenAiProvider::class);
});

test('array default throws helpful error', function () {
    config(['ai.default' => ['text' => 'anthropic']]);

    expect(fn () => Ai::textProvider())
        ->toThrow(InvalidArgumentException::class, "'default' => 'anthropic'");
});

test('capability keyed provider list throws helpful error', function () {
    expect(fn () => Provider::formatProviderAndModelList(['text' => 'anthropic']))
        ->toThrow(InvalidArgumentException::class, 'capability-keyed format');
});

test('failover provider list still works', function () {
    expect(Provider::formatProviderAndModelList(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']);
});
