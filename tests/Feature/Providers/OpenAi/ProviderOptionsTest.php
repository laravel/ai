<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('provider options are included in openai request body', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'reasoning.effort') === 'high'
            && data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('reasoning', $body)
            && ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'openai');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $firstBody = json_decode($requests[0][0]->body(), true);
    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(data_get($firstBody, 'tool_choice'))->toBe('auto')
        ->and($firstBody)->toHaveKey('tools')
        ->and(data_get($followUpBody, 'tool_choice'))->toBe('auto')
        ->and($followUpBody)->toHaveKey('tools')
        ->and(data_get($followUpBody, 'reasoning.effort'))->toBe('high')
        ->and(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5)
        ->and($followUpBody)->toHaveKey('previous_response_id');
});

function fakeOpenAiToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
