<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function anthropicToolSearchMapper(): object
{
    return new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider, string $model, ?TextGenerationOptions $options): array
        {
            return $this->mapTools($tools, $provider, $model, $options);
        }
    };
}

function anthropicSupportingProvider(bool $supports = true): Provider
{
    return new class($supports) extends Provider implements SupportsToolSearch
    {
        public function __construct(private bool $supports)
        {
            //
        }

        public function supportsToolSearch(string $model): bool
        {
            return $this->supports;
        }
    };
}

test('prepends the regex tool search entry by default and defers marked tools', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        anthropicSupportingProvider(),
        'claude-opus-4-8',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped[0])->toBe([
        'type' => 'tool_search_tool_regex_20251119',
        'name' => 'tool_search_tool_regex',
    ]);
    expect($mapped[0])->not->toHaveKey('defer_loading');

    $tools = array_slice($mapped, 1);
    $deferred = collect($tools)->firstWhere('defer_loading', true);
    $nonDeferred = collect($tools)->filter(fn ($t) => ! isset($t['defer_loading']));

    expect($deferred)->not->toBeNull()
        ->and($deferred['description'])->toContain('deferred')
        ->and($nonDeferred)->toHaveCount(1);
});

test('prepends the bm25 tool search entry when that strategy is selected', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        anthropicSupportingProvider(),
        'claude-opus-4-8',
        new TextGenerationOptions(toolSearchStrategy: 'bm25'),
    );

    expect($mapped[0])->toBe([
        'type' => 'tool_search_tool_bm25_20251119',
        'name' => 'tool_search_tool_bm25',
    ]);
});

test('emits tools unchanged when the agent has not opted in', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        anthropicSupportingProvider(),
        'claude-opus-4-8',
        new TextGenerationOptions,
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search_tool_regex_20251119')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('silently skips deferral when the model does not support tool search', function () {
    $mapped = anthropicToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        anthropicSupportingProvider(supports: false),
        'claude-haiku-3',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('throws when every tool is deferred because Anthropic requires a non-deferred tool', function () {
    anthropicToolSearchMapper()->map(
        [new DeferredTool, new DeferredTool],
        anthropicSupportingProvider(),
        'claude-opus-4-8',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );
})->throws(LogicException::class, 'at least one non-deferred tool');
