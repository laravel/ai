<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

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
            fakeOllamaToolCallForOptions(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'ollama');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(array_key_exists('options', $followUpBody))->toBeTrue();
});

function fakeOllamaToolCallForOptions(): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_123',
                'function' => [
                    'name' => 'FixedNumberGenerator',
                    'arguments' => (object) [],
                ],
            ]],
        ],
        'done_reason' => 'tool_calls',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ]);
}
