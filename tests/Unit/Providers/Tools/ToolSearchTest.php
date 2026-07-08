<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\DeferredTool;

test('defaults to an empty tool set', function () {
    expect((new ToolSearch)->tools)->toBe([]);
});

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
        fn (Lab $provider) => $provider === Lab::Anthropic ? ['strategy' => 'bm25'] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['strategy' => 'bm25'])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});
