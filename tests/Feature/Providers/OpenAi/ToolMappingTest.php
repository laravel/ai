<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

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
