<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->not->toHaveKey('previous_response_id');

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
        'api.openai.com/*' => Http::sequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeUniqueOpenAiToolCallResponse(): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_tool_'.$id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
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
