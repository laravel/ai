<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\NullableToolAgent;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function () {
    Http::fake([
        '*' => fakeGroqResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

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
        '*' => fakeGroqResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'groq');

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

test('nullable tool parameters preserve JSON Schema array type format', function () {
    Http::fake([
        '*' => fakeGroqResponse('ok'),
    ]);

    (new NullableToolAgent)->prompt('Test nullable params', provider: 'groq');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $props = $tool['function']['parameters']['properties'] ?? [];

        // Non-nullable param should have plain string type
        if ($props['name']['type'] !== 'string') {
            return false;
        }

        // Nullable string param should use JSON Schema array format ["string", "null"]
        if ($props['email']['type'] !== ['string', 'null']) {
            return false;
        }

        // Nullable integer param should use JSON Schema array format ["integer", "null"]
        if ($props['age']['type'] !== ['integer', 'null']) {
            return false;
        }

        // Groq should NOT have OpenAPI-style nullable flag
        if (isset($props['email']['nullable']) || isset($props['age']['nullable'])) {
            return false;
        }

        return true;
    });
});

test('tool parameters are not wrapped in schema definition', function () {
    Http::fake([
        '*' => fakeGroqResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'groq');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});
