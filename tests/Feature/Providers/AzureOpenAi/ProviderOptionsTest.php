<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('provider options are included in azure request body', function () {
    Http::fake([
        '*' => fakeAzureResponse('Hello'),
    ]);

    (new ProviderOptionsAgent)->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'frequency_penalty') === 0.5
            && data_get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        '*' => fakeAzureResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'azure');

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
            fakeAzureProviderOptionsToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'azure');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(data_get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});

function fakeAzureProviderOptionsToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_azure_tool_123',
        'status' => 'completed',
        'model' => 'gpt-4o',
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
