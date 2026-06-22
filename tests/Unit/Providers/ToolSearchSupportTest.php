<?php

use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;

function providerWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

test('OpenAI gates tool search to gpt-5.4 and newer', function (string $model, bool $supported) {
    expect(providerWithoutConstructor(OpenAiProvider::class)->supportsToolSearch($model))->toBe($supported);
})->with([
    ['gpt-5.4', true],
    ['gpt-5.4-nano', true],
    ['gpt-5.4-pro', true],
    ['gpt-5.5', true],
    ['gpt-6', true],
    ['gpt-5.1', false],
    ['gpt-5', false],
    ['gpt-4o', false],
    ['o3', false],
    ['', false],
]);

test('Anthropic gates tool search to Sonnet/Opus 4.0+, Haiku 4.5+, and Fable/Mythos 5+', function (string $model, bool $supported) {
    expect(providerWithoutConstructor(AnthropicProvider::class)->supportsToolSearch($model))->toBe($supported);
})->with([
    ['claude-sonnet-4-6', true],
    ['claude-opus-4-8', true],
    ['claude-opus-4-0', true],
    ['claude-haiku-4-5-20251001', true],
    ['claude-fable-5', true],
    ['claude-mythos-5', true],
    ['claude-haiku-4-4', false],
    ['claude-3-5-sonnet-20241022', false],
    ['claude-3-opus-20240229', false],
    ['', false],
]);
