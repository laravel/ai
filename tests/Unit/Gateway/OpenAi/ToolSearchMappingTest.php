<?php

use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function openAiToolSearchMapper(): object
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

function openAiSupportingProvider(bool $supports = true): Provider
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

function openAiPlainProvider(): Provider
{
    return new class extends Provider
    {
        public function __construct()
        {
            //
        }
    };
}

test('deferred tool gets defer_loading and a tool_search entry is prepended when search is active', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        openAiSupportingProvider(),
        'gpt-5.4',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped[0])->toBe(['type' => 'tool_search']);

    $tools = array_slice($mapped, 1);
    $deferred = collect($tools)->firstWhere('defer_loading', true);
    $nonDeferred = collect($tools)->filter(fn ($t) => ! isset($t['defer_loading']));

    expect($deferred)->not->toBeNull()
        ->and($deferred['description'])->toContain('deferred')
        ->and($nonDeferred)->toHaveCount(1);
});

test('no tool_search entry or defer_loading when the agent has not opted in', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        openAiSupportingProvider(),
        'gpt-5.4',
        new TextGenerationOptions, // toolSearchStrategy === null
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('silently skips deferral when the provider model does not support tool search', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        openAiSupportingProvider(supports: false),
        'gpt-5.3',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('silently skips deferral when the provider does not implement SupportsToolSearch', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new DeferredTool, new NonStrictTool],
        openAiPlainProvider(),
        'gpt-5.4',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->contains(fn ($t) => isset($t['defer_loading'])))->toBeFalse();
});

test('no tool_search entry is added when no tools are deferred even if search is active', function () {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool],
        openAiSupportingProvider(),
        'gpt-5.4',
        new TextGenerationOptions(toolSearchStrategy: 'regex'),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->pluck('type'))->not->toContain('tool_search');
});
