<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\DeferredTool;

test('withTools returns a new instance preserving the strategy and provider options', function () {
    $tool = new DeferredTool;
    $original = (new ToolSearch(strategy: 'bm25'))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $updated = $original->withTools([$tool]);

    expect($updated)->not->toBe($original)
        ->and($updated->tools)->toBe([$tool])
        ->and($original->tools)->toBe([])
        ->and($updated->strategy)->toBe('bm25')
        ->and($updated->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']]);
});

test('carries provider options for a specific provider', function () {
    $search = (new ToolSearch)->withProviderOptions(
        fn (Lab $provider) => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});

test('rejects an unknown search strategy', function () {
    new ToolSearch(strategy: 'semantic');
})->throws(InvalidArgumentException::class, 'Invalid tool search strategy [semantic]');
