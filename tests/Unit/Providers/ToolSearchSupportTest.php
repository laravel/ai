<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;

function makeProvider(string $class): object
{
    return new $class(
        Mockery::mock(Gateway::class),
        ['name' => 'test', 'driver' => 'test', 'key' => 'test'],
        Mockery::mock(Dispatcher::class),
    );
}

test('the OpenAI provider implements the tool search capability', function () {
    expect(makeProvider(OpenAiProvider::class))->toBeInstanceOf(SupportsToolSearch::class);
});

test('the OpenAI provider supports tool search on gpt-5.4 and later', function (string $model, bool $expected) {
    expect(makeProvider(OpenAiProvider::class)->supportsToolSearch($model))->toBe($expected);
})->with([
    ['gpt-5.4', true],
    ['gpt-5.4-2026-01-01', true],
    ['gpt-5.5', true],
    ['gpt-6', true],
    ['gpt-5.3', false],
    ['gpt-5', false],
    ['gpt-4o', false],
]);

test('the Anthropic provider implements the tool search capability', function () {
    expect(makeProvider(AnthropicProvider::class))->toBeInstanceOf(SupportsToolSearch::class);
});

test('the Anthropic provider supports tool search on Sonnet/Opus 4.0+ and Haiku 4.5+', function (string $model, bool $expected) {
    expect(makeProvider(AnthropicProvider::class)->supportsToolSearch($model))->toBe($expected);
})->with([
    ['claude-opus-4-8', true],
    ['claude-sonnet-4-6', true],
    ['claude-sonnet-4-0', true],
    ['claude-haiku-4-5', true],
    ['claude-haiku-4-5-20251001', true],
    ['claude-haiku-4-0', false],
    ['claude-opus-3-5', false],
    ['claude-3-5-sonnet-20241022', false],
]);
