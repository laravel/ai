<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return $function['parameters']['type'] === 'object'
            && array_key_exists('min', $function['parameters']['properties'])
            && array_key_exists('max', $function['parameters']['properties'])
            && in_array('min', $function['parameters']['required'])
            && in_array('max', $function['parameters']['required'])
            && $function['parameters']['additionalProperties'] === false;
    });
});

test('tool with empty schema includes parameters', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('72019')]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return array_key_exists('parameters', $function)
            && $function['parameters']['type'] === 'object'
            && $function['parameters']['properties'] === []
            && $function['parameters']['required'] === []
            && $function['parameters']['additionalProperties'] === false;
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});

test('unsupported provider tools throw runtime exception', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    expect(fn (): AgentResponse => agent(tools: [new FileSearch(['store'])])->prompt('Search', provider: 'openrouter'))
        ->toThrow(RuntimeException::class, 'OpenRouter does not support [FileSearch] provider tools.');
});

test('web fetch tool is sent as openrouter:web_fetch type', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebFetch])->prompt('Read https://laravel.com', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

        return $tool !== null && ! array_key_exists('parameters', $tool);
    });
});

test('web fetch tool sends max_uses when maxSearches is set', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebFetch)->max(5)])->prompt('Read https://laravel.com', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

        return data_get($tool, 'parameters.max_uses') === 5;
    });
});

test('web fetch tool sends allowed_domains', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebFetch)->allow(['example.com', 'laravel.com'])])->prompt('Read the docs', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

        return data_get($tool, 'parameters.allowed_domains') === ['example.com', 'laravel.com'];
    });
});

test('web fetch tool forwards provider options into parameters', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    $fetch = (new WebFetch)->withProviderOptions([
        'engine' => 'exa',
        'max_content_tokens' => 50000,
        'blocked_domains' => ['private.example.com'],
    ]);

    agent(tools: [$fetch])->prompt('Read the docs', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_fetch');

        return data_get($tool, 'parameters.engine') === 'exa'
            && data_get($tool, 'parameters.max_content_tokens') === 50000
            && data_get($tool, 'parameters.blocked_domains') === ['private.example.com'];
    });
});

test('web fetch and web search can be used together', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebSearch, new WebFetch])->prompt('Research this', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $types = collect(data_get(json_decode($request->body(), true), 'tools'))->pluck('type')->all();

        return in_array('openrouter:web_search', $types, true)
            && in_array('openrouter:web_fetch', $types, true);
    });
});

test('web search tool is sent as openrouter:web_search type', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return $tool !== null && ! array_key_exists('parameters', $tool);
    });
});

test('web search tool sends max_uses when maxSearches is set', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebSearch)->max(5)])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return data_get($tool, 'parameters.max_uses') === 5;
    });
});

test('web search tool forwards max_results through provider options', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    $search = (new WebSearch)->max(3)->withProviderOptions(['max_results' => 10]);

    agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return data_get($tool, 'parameters.max_uses') === 3
            && data_get($tool, 'parameters.max_results') === 10;
    });
});

test('web search tool sends allowed_domains', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebSearch)->allow(['example.com', 'laravel.com'])])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return data_get($tool, 'parameters.allowed_domains') === ['example.com', 'laravel.com'];
    });
});

test('web search tool forwards provider options into parameters', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    $search = (new WebSearch)->withProviderOptions([
        'engine' => 'exa',
        'max_total_results' => 20,
        'search_context_size' => 'medium',
        'excluded_domains' => ['reddit.com'],
    ]);

    agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return data_get($tool, 'parameters.engine') === 'exa'
            && data_get($tool, 'parameters.max_total_results') === 20
            && data_get($tool, 'parameters.search_context_size') === 'medium'
            && data_get($tool, 'parameters.excluded_domains') === ['reddit.com'];
    });
});

test('tool with a name() method emits the declared name', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});
