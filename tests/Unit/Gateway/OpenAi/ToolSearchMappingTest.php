<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebFetch;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function openAiToolSearchMapper(): object
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

test('emits the tool_search entry and defers tools flagged with defer_loading', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool],
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

test('emits a single tool_search entry for multiple deferred tools', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool, new DeferredTool],
        openAiToolSearchProvider(),
    );

    expect(collect($mapped)->where('type', 'tool_search'))->toHaveCount(1)
        ->and(collect($mapped)->where('defer_loading', true))->toHaveCount(2);
});

test('throws for a provider tool OpenAI cannot map instead of emitting an empty entry', function () {
    expect(fn () => openAiToolSearchMapper()->map([new WebFetch], openAiToolSearchProvider()))
        ->toThrow(RuntimeException::class, 'does not support the [WebFetch] tool');
});

test('does not emit a tool_search entry when no tool is deferred', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool([])],
        openAiToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
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
            return 'openai';
        }
    };

    openAiToolSearchMapper()->map([new NonStrictTool, new DeferredTool], $provider);
})->throws(LogicException::class, 'does not support tool search');
