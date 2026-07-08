<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function anthropicToolSearchMapper(): object
{
    return new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };
}

function anthropicToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch
    {
        public function __construct()
        {
            //
        }
    };
}

test('emits the regex tool search entry and defers the tools nested in the ToolSearch tool', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        anthropicToolSearchProvider(),
    );

    $search = collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119');

    expect($search)->toBe([
        'type' => 'tool_search_tool_regex_20251119',
        'name' => 'tool_search_tool_regex',
    ]);
    expect($search)->not->toHaveKey('defer_loading');

    $deferred = collect($mapped)->firstWhere('defer_loading', true);
    $nonDeferred = collect($mapped)->filter(
        fn ($t) => isset($t['input_schema']) && ! isset($t['defer_loading'])
    );

    expect($deferred)->not->toBeNull()
        ->and($deferred['description'])->toContain('deferred')
        ->and($nonDeferred)->toHaveCount(1);
});

test('emits the bm25 tool search entry when that strategy is set via provider options', function () {
    $search = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['strategy' => 'bm25']);

    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, $search],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_bm25_20251119'))->toBe([
        'type' => 'tool_search_tool_bm25_20251119',
        'name' => 'tool_search_tool_bm25',
    ]);
});

test('does not leak the strategy option onto the tool search entry', function () {
    $search = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['strategy' => 'regex']);

    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, $search],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'))
        ->not->toHaveKey('strategy');
});

test('forwards provider options onto the tool search entry', function () {
    $search = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $mapped = anthropicToolSearchMapper()->map([new NonStrictTool, $search], anthropicToolSearchProvider());

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'))
        ->toBe([
            'type' => 'tool_search_tool_regex_20251119',
            'name' => 'tool_search_tool_regex',
            'cache_control' => ['type' => 'ephemeral'],
        ]);
});

test('merges options and strategy from every ToolSearch tool onto the single entry', function () {
    $first = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);
    $second = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['strategy' => 'bm25']);

    $mapped = anthropicToolSearchMapper()->map([new NonStrictTool, $first, $second], anthropicToolSearchProvider());

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_bm25_20251119'))->toBe([
        'type' => 'tool_search_tool_bm25_20251119',
        'name' => 'tool_search_tool_bm25',
        'cache_control' => ['type' => 'ephemeral'],
    ]);
});

test('emits a single tool search entry when multiple ToolSearch tools are present', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool]), new ToolSearch(tools: [new DeferredTool])],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->filter(fn ($t) => str_starts_with($t['type'] ?? '', 'tool_search')))->toHaveCount(1)
        ->and(collect($mapped)->where('defer_loading', true))->toHaveCount(2);
});

test('does not emit a tool_search entry when no ToolSearch tool is present', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool],
        anthropicToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('throws when no non-deferred tool accompanies the ToolSearch tool because Anthropic requires one', function () {
    anthropicToolSearchMapper()->map(
        [new ToolSearch(tools: [new DeferredTool, new DeferredTool])],
        anthropicToolSearchProvider(),
    );
})->throws(LogicException::class, 'at least one non-deferred tool');

test('counts a server tool as non-deferred so a ToolSearch alongside web search is allowed', function () {
    $provider = new class extends Provider implements SupportsToolSearch, SupportsWebSearch
    {
        public function __construct() {}

        public function webSearchToolOptions(WebSearch $search): array
        {
            return [];
        }
    };

    $mapped = anthropicToolSearchMapper()->map(
        [new WebSearch, new ToolSearch(tools: [new DeferredTool])],
        $provider,
    );

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'))->not->toBeNull()
        ->and(collect($mapped)->firstWhere('type', 'web_search_20250305'))->not->toBeNull();
});

test('skips an empty ToolSearch tool without emitting a search entry', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch],
        anthropicToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->contains(fn ($t) => str_starts_with($t['type'] ?? '', 'tool_search')))->toBeFalse();
});

test('throws when the provider does not support tool search', function () {
    $provider = new class extends Provider
    {
        public function __construct()
        {
            //
        }

        public function name(): string
        {
            return 'anthropic';
        }
    };

    anthropicToolSearchMapper()->map([new NonStrictTool, new ToolSearch(tools: [new DeferredTool])], $provider);
})->throws(LogicException::class, 'does not support tool search');
