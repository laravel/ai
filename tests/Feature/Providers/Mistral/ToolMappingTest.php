<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\FileSearch;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function () {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

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
    Http::fake(['*' => $this->fakeTextResponse('72019')]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

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

test('provider tools throw runtime exception', function () {
    Http::fake(['*' => $this->fakeTextResponse()]);

    agent(
        tools: [new FileSearch(['store_1'])],
    )->prompt('Search for something', provider: 'mistral');
})->throws(RuntimeException::class, 'Mistral does not support');

test('tool parameters are not wrapped in schema definition', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'mistral');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});
