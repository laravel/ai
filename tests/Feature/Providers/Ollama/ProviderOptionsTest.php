<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\OllamaStructuredProviderOptionsAgent;
use Tests\Fixtures\Agents\OllamaTopLevelOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('provider options are included in ollama options object', function () {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return array_key_exists('options', $body);
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('options', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'ollama');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(array_key_exists('options', $followUpBody))->toBeTrue();
});

test('top-level provider options are placed at the body root, not inside options', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new OllamaTopLevelOptionsAgent)->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ($body['format'] ?? null) === 'json'
            && ($body['keep_alive'] ?? null) === '10m'
            && ($body['logprobs'] ?? null) === true
            && ! array_key_exists('format', $body['options'] ?? [])
            && ! array_key_exists('keep_alive', $body['options'] ?? [])
            && ! array_key_exists('logprobs', $body['options'] ?? []);
    });
});

test('model parameters from provider options remain inside the options object', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new OllamaTopLevelOptionsAgent)->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ($body['options']['num_ctx'] ?? null) === 8192
            && ! array_key_exists('num_ctx', $body);
    });
});

test('structured output schema is not overwritten by a provider options format', function () {
    Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    (new OllamaStructuredProviderOptionsAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return array_key_exists('format', $body)
            && is_array($body['format']);
    });
});
