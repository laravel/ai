<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.nvidia' => [
        ...config('ai.providers.nvidia'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function () {
    Http::fake([
        '*' => fakeNvidiaResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'nvidia');

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
    Http::fake([
        '*' => fakeNvidiaResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'nvidia');

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
