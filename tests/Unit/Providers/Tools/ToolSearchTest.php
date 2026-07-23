<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\DeferredTool;

test('accepts a deferred tool set', function () {
    $tool = new DeferredTool;

    expect((new ToolSearch(tools: [$tool]))->tools)->toBe([$tool]);
});

test('withTools replaces the deferred tool set', function () {
    $tool = new DeferredTool;

    expect((new ToolSearch)->withTools([$tool])->tools)->toBe([$tool]);
});

test('carries provider options for a specific provider', function () {
    $search = (new ToolSearch)->withProviderOptions(
        fn (Lab $provider) => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});

test('accepts a search strategy', function () {
    expect((new ToolSearch(strategy: 'bm25'))->strategy)->toBe('bm25')
        ->and((new ToolSearch)->strategy)->toBeNull();
});

test('rejects an unknown search strategy', function () {
    new ToolSearch(strategy: 'semantic');
})->throws(InvalidArgumentException::class, 'Invalid tool search strategy [semantic]');
