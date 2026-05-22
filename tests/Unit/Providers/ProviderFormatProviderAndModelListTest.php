<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Provider;

test('Lab enum is normalized to its string value with the given model', function () {
    expect(Provider::formatProviderAndModelList(Lab::Anthropic, 'claude-opus-4-7-v1'))
        ->toBe(['anthropic' => 'claude-opus-4-7-v1']);
});

test('Lab enum without a model produces a null model entry', function () {
    expect(Provider::formatProviderAndModelList(Lab::Anthropic))
        ->toBe(['anthropic' => null]);
});

test('string provider with a model returns a single-entry list', function () {
    expect(Provider::formatProviderAndModelList('openai', 'gpt-4'))
        ->toBe(['openai' => 'gpt-4']);
});

test('string provider without a model produces a null model entry', function () {
    expect(Provider::formatProviderAndModelList('openai'))
        ->toBe(['openai' => null]);
});

test('mixed numeric and associative keys honor each entry independently', function () {
    expect(Provider::formatProviderAndModelList([
        'anthropic',
        'openai' => 'gpt-4',
    ]))->toBe(['anthropic' => null, 'openai' => 'gpt-4']);
});

test('mixed keys apply the top-level model only to numeric entries', function () {
    expect(Provider::formatProviderAndModelList(
        ['anthropic', 'openai' => 'gpt-4'],
        'default-model',
    ))->toBe(['anthropic' => 'default-model', 'openai' => 'gpt-4']);
});

test('Lab enum value in a numeric position is normalized to its string value', function () {
    expect(Provider::formatProviderAndModelList([
        Lab::Anthropic,
        Lab::OpenAI->value => 'gpt-4',
    ]))->toBe(['anthropic' => null, 'openai' => 'gpt-4']);
});

test('Lab enum as an associative key is normalized to its string value', function () {
    expect(Provider::formatProviderAndModelList([
        Lab::Anthropic->value => 'claude-opus-4-7',
    ]))->toBe(['anthropic' => 'claude-opus-4-7']);
});
