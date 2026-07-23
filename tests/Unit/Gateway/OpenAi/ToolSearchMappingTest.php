<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function openAiToolSearchMapper(): object
{
    return new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider, bool $stateless = false): array
        {
            return $this->mapTools($tools, $provider, $stateless);
        }
    };
}

function openAiToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch
    {
        public function __construct()
        {
            //
        }

        public function name(): string
        {
            return 'openai';
        }
    };
}

test('emits the tool_search entry and defers the tools nested in the ToolSearch tool', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        openAiToolSearchProvider(),
    );

    $search = collect($mapped)->firstWhere('type', 'tool_search');
    $deferred = collect($mapped)->firstWhere('defer_loading', true);
    $nonDeferred = collect($mapped)->filter(
        fn ($t) => ($t['type'] ?? null) === 'function' && ! isset($t['defer_loading'])
    );

    expect($search)->toBe(['type' => 'tool_search'])
        ->and($deferred)->not->toBeNull()
        ->and($deferred['description'])->toContain('deferred')
        ->and($nonDeferred)->toHaveCount(1);
});

test('throws for a provider tool OpenAI cannot map instead of emitting an empty entry', function () {
    expect(fn () => openAiToolSearchMapper()->map([new WebFetch], openAiToolSearchProvider()))
        ->toThrow(RuntimeException::class, 'does not support the [WebFetch] tool');
});

test('throws when response storage is disabled because hosted search requires stored responses', function () {
    expect(fn () => openAiToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        openAiToolSearchProvider(),
        stateless: true,
    ))->toThrow(LogicException::class, 'store=false');
});

test('forwards provider options onto the tool_search entry', function () {
    $search = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(['foo' => 'bar']);

    $mapped = openAiToolSearchMapper()->map([new NonStrictTool, $search], openAiToolSearchProvider());

    expect(collect($mapped)->firstWhere('type', 'tool_search'))
        ->toBe(['type' => 'tool_search', 'foo' => 'bar']);
});

test('skips an empty ToolSearch tool without emitting a tool_search entry', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch],
        openAiToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search');
});

test('does not emit a tool_search entry when no ToolSearch tool is present', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool],
        openAiToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});
