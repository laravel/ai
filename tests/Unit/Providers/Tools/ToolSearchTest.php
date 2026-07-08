<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ProviderToolSearch;
use Tests\Fixtures\Tools\DeferredTool;

test('defaults to an empty tool set', function () {
    expect((new ProviderToolSearch)->tools)->toBe([]);
});

test('accepts a deferred tool set', function () {
    $tool = new DeferredTool;

    expect((new ProviderToolSearch(tools: [$tool]))->tools)->toBe([$tool]);
});

test('withTools replaces the deferred tool set', function () {
    $tool = new DeferredTool;

    expect((new ProviderToolSearch)->withTools([$tool])->tools)->toBe([$tool]);
});

test('carries provider options for a specific provider', function () {
    $search = (new ProviderToolSearch)->withProviderOptions(Lab::Anthropic, ['strategy' => 'bm25']);

    expect($search->providerOptions(Lab::Anthropic))->toBe(['strategy' => 'bm25'])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});
