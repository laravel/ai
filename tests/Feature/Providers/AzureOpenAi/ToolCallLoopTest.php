<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'azure',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id');

    $hasFunctionCallOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasFunctionCallOutput = true;
        }
    }

    expect($hasFunctionCallOutput)->toBeTrue();
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'azure',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt(
        'Generate numbers',
        provider: 'azure',
    );

    expect((string) $response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

function fakeUniqueAzureToolCallResponse(): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_azure_tool_'.$id,
        'status' => 'completed',
        'model' => 'gpt-4o',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_'.$id,
            'call_id' => 'call_'.$id,
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
