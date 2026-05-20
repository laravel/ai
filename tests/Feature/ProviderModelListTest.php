<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Provider;

test('numeric provider list uses passed model as default for each provider', function () {
    expect(Provider::formatProviderAndModelList(['anthropic', 'openai'], 'claude-3-5-sonnet'))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'claude-3-5-sonnet']);
});

test('numeric provider list retains null model when none given', function () {
    expect(Provider::formatProviderAndModelList(['anthropic', 'openai']))
        ->toBe(['anthropic' => null, 'openai' => null]);
});

test('associative provider list preserves per-provider models', function () {
    expect(Provider::formatProviderAndModelList(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']);
});

test('Lab enum provider list uses passed model as default', function () {
    expect(Provider::formatProviderAndModelList([Lab::Anthropic, Lab::OpenAI], 'claude-3-5-sonnet'))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'claude-3-5-sonnet']);
});

