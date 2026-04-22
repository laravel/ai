<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in deepseek request body', function () {
    Http::fake([
        '*' => fakeDeepSeekResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        '*' => fakeDeepSeekResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeDeepSeekProviderOptionsToolCallResponse(),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'deepseek');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});

function fakeDeepSeekProviderOptionsToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-deepseek-tool-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
