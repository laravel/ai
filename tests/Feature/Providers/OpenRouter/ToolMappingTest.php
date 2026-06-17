<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function () {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
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

test('tool with empty schema includes parameters', function () {
    Http::fake(['*' => fakeOpenRouterResponse('72019')]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
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

test('tool parameters are not wrapped in schema definition', function () {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});

test('unsupported provider tools throw runtime exception', function () {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    expect(fn () => agent(tools: [new WebFetch])->prompt('Search', provider: 'openrouter'))
        ->toThrow(RuntimeException::class, 'OpenRouter does not support [WebFetch] provider tools.');
});

test('web search tool is sent as openrouter:web_search type', function () {
    Http::fake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'openrouter:web_search');

        return $tool !== null;
    });
});

test('tool with a name() method emits the declared name', function () {
    Http::fake(['*' => fakeOpenRouterResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});
