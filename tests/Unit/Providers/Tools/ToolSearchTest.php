<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\DeferredTool;

test('accepts a deferred tool set', function () {
    $tool = new DeferredTool;

    expect((new ToolSearch(tools: [$tool]))->tools)->toBe([$tool]);
});

test('withTools returns a new instance without mutating the original', function () {
    $tool = new DeferredTool;
    $original = new ToolSearch;

    $updated = $original->withTools([$tool]);

    expect($updated)->not->toBe($original)
        ->and($updated->tools)->toBe([$tool])
        ->and($original->tools)->toBe([]);
});

test('withTools preserves the strategy and provider options', function () {
    $original = (new ToolSearch(strategy: 'bm25'))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $updated = $original->withTools([new DeferredTool]);

    expect($updated->strategy)->toBe('bm25')
        ->and($updated->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']]);
});

test('carries provider options for a specific provider', function () {
    $search = (new ToolSearch)->withProviderOptions(
        fn (Lab $provider) => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});

test('accepts any search strategy', function () {
    expect((new ToolSearch(strategy: 'bm25'))->strategy)->toBe('bm25')
        ->and((new ToolSearch(strategy: 'semantic'))->strategy)->toBe('semantic')
        ->and((new ToolSearch)->strategy)->toBeNull();
});
