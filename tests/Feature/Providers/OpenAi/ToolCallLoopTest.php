<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
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

test('multi step tool loop returns accumulated response shape', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt(
        'Generate numbers',
        provider: 'openai',
    );

    expect((string) $response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

test('a forced tool choice is released on the follow up request', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiRandomNumberToolCallResponse(),
            fakeOpenAiResponse('The number is 7'),
        ]),
    ]);

    (new ToolChoiceAgent('required'))->prompt('Generate a random number', provider: 'openai');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2)
        ->and(json_decode($recorded[0][0]->body(), true)['tool_choice'])->toBe('required')
        ->and(json_decode($recorded[1][0]->body(), true)['tool_choice'])->toBe('auto');
});

function fakeOpenAiRandomNumberToolCallResponse(): PromiseInterface
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
            'name' => 'RandomNumberGenerator',
            'arguments' => '{"min":1,"max":10}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

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
