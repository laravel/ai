<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ToolSearch;
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

function openAiProvider(): Provider
{
    return new class extends Provider
    {
        public function __construct()
        {
            //
        }
    };
}

test('emits the tool_search entry and defers the tools nested in the ToolSearch tool', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        openAiProvider(),
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

test('forwards provider options onto the tool_search entry', function () {
    $search = (new ToolSearch(tools: [new DeferredTool]))
        ->withProviderOptions(Lab::OpenAI, ['foo' => 'bar']);

    $mapped = openAiToolSearchMapper()->map([new NonStrictTool, $search], openAiProvider());

    expect(collect($mapped)->firstWhere('type', 'tool_search'))
        ->toBe(['type' => 'tool_search', 'foo' => 'bar']);
});

test('does not emit a tool_search entry when no ToolSearch tool is present', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool, new DeferredTool],
        openAiProvider(),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});
