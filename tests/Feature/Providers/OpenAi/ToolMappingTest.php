<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\NonStrictTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes strict compliant schema', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === true
            && $tool['parameters']['type'] === 'object'
            && array_key_exists('min', $tool['parameters']['properties'])
            && array_key_exists('max', $tool['parameters']['properties'])
            && in_array('min', $tool['parameters']['required'])
            && in_array('max', $tool['parameters']['required'])
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool without a name() method falls back to class basename for openai', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('name')->all();

        return in_array('FixedNumberGenerator', $names, true);
    });
});

test('tool without Strict attribute sends strict false and honors developer-declared required fields', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === false
            && $tool['parameters']['required'] === ['query']
            && array_key_exists('limit', $tool['parameters']['properties']);
    });
});

test('tool with empty schema includes strict compliant parameters', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['strict'] === true
            && array_key_exists('parameters', $tool)
            && $tool['parameters']['type'] === 'object'
            && $tool['parameters']['properties'] === []
            && $tool['parameters']['required'] === []
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('web search tool sends type web_search', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return $tool !== null;
    });
});

test('web search tool omits openai-specific options by default', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return ! array_key_exists('external_web_access', $tool);
    });
});

test('web search tool forwards openai provider options into the tool payload', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch)->withProviderOptions('openai', [
            'external_web_access' => false,
            'search_context_size' => 'high',
        ]),
    ])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'external_web_access') === false
            && data_get($tool, 'search_context_size') === 'high';
    });
});

test('web search tool ignores provider options keyed to another provider', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch)->withProviderOptions('anthropic', ['external_web_access' => false]),
    ])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return ! array_key_exists('external_web_access', $tool);
    });
});

test('web search tool sends allowed_domains filter', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [(new WebSearch)->allow(['example.com', 'docs.example.com'])])
        ->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'filters.allowed_domains') === ['example.com', 'docs.example.com'];
    });
});

test('web search tool sends blocked_domains via provider options', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch)->withProviderOptions('openai', [
            'filters' => ['blocked_domains' => ['spam.com', 'ads.example.com']],
        ]),
    ])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'filters.blocked_domains') === ['spam.com', 'ads.example.com'];
    });
});

test('web search tool merges allow() with blocked_domains provider option', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch)
            ->allow(['good.com'])
            ->withProviderOptions('openai', ['filters' => ['blocked_domains' => ['bad.com']]]),
    ])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'filters.allowed_domains') === ['good.com']
            && data_get($tool, 'filters.blocked_domains') === ['bad.com'];
    });
});

test('web search tool omits filters when no domains configured', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return ! array_key_exists('filters', $tool);
    });
});

test('web search tool sends user_location when location is set', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [(new WebSearch)->location(city: 'Warsaw', country: 'PL')])
        ->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'user_location.type') === 'approximate'
            && data_get($tool, 'user_location.city') === 'Warsaw'
            && data_get($tool, 'user_location.country') === 'PL';
    });
});

test('web search tool omits user_location when no location set', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch])->prompt('Search', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return ! array_key_exists('user_location', $tool);
    });
});
