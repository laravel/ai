<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
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

test('emits the regex tool search entry and defers tools flagged with defer_loading', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool],
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

test('emits the bm25 tool search entry when a deferred tool sets that strategy', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool(['defer_loading' => true, 'strategy' => 'bm25'])],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_bm25_20251119'))->toBe([
        'type' => 'tool_search_tool_bm25_20251119',
        'name' => 'tool_search_tool_bm25',
    ]);
});

test('does not leak the strategy option onto the deferred tool definition', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool(['defer_loading' => true, 'strategy' => 'bm25'])],
        anthropicToolSearchProvider(),
    );

    $deferred = collect($mapped)->firstWhere('defer_loading', true);

    expect($deferred)->not->toHaveKey('strategy')
        ->and($deferred['defer_loading'])->toBeTrue();
});

test('emits a single tool search entry for multiple deferred tools', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool, new DeferredTool],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->filter(fn ($t) => str_starts_with($t['type'] ?? '', 'tool_search')))->toHaveCount(1)
        ->and(collect($mapped)->where('defer_loading', true))->toHaveCount(2);
});

test('does not emit a tool search entry when no tool is deferred', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool],
        anthropicToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('throws when every tool is deferred because Anthropic requires one non-deferred tool', function () {
    anthropicToolSearchMapper()->map(
        [new DeferredTool, new DeferredTool],
        anthropicToolSearchProvider(),
    );
})->throws(LogicException::class, 'at least one non-deferred tool');

test('counts a server tool as non-deferred so a deferred tool alongside web search is allowed', function () {
    $provider = new class extends Provider implements SupportsToolSearch, SupportsWebSearch
    {
        public function __construct() {}

        public function webSearchToolOptions(WebSearch $search): array
        {
            return [];
        }
    };

    $mapped = anthropicToolSearchMapper()->map(
        [new WebSearch, new DeferredTool],
        $provider,
    );

    expect(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'))->not->toBeNull()
        ->and(collect($mapped)->firstWhere('type', 'web_search_20250305'))->not->toBeNull();
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

    anthropicToolSearchMapper()->map([new NonStrictTool, new DeferredTool], $provider);
})->throws(LogicException::class, 'does not support tool search');
