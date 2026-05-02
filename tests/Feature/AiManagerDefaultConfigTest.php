<?php

use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\Provider;

use function Laravel\Ai\agent;

test('string default works', function () {
    config(['ai.default' => 'openai']);

    expect(app(AiManager::class)->getDefaultInstance())->toBe('openai');
});

test('array default via Ai facade throws helpful error', function () {
    config(['ai.default' => ['text' => 'anthropic']]);

    expect(fn () => app(AiManager::class)->getDefaultInstance())
        ->toThrow(InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
});

test('array default via agent prompt throws helpful error', function () {
    config(['ai.default' => ['text' => 'anthropic']]);

    expect(fn () => agent(instructions: 'test')->prompt('hello'))
        ->toThrow(InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
});

test('failover provider list still works', function () {
    expect(Provider::formatProviderAndModelList(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']);
});
