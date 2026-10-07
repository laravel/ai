<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

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
    Http::fake([
        '*' => fakeGroqResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'groq');

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

test('tool with a name() method emits the declared name', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('ok'),
    ]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('function.name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    Http::fake([
        '*' => fakeGroqResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});

test('web search tool is sent as browser_search type', function (): void {
    Http::fake(['*' => fakeGroqResponse('done')]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        return data_get(json_decode($request->body(), true), 'tools') === [['type' => 'browser_search']];
    });
});

test('code execution tool is sent as code_interpreter type', function (): void {
    Http::fake(['*' => fakeGroqResponse('done')]);

    agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        return data_get(json_decode($request->body(), true), 'tools') === [['type' => 'code_interpreter']];
    });
});
