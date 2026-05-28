<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.qianfan' => [
        ...config('ai.providers.qianfan'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake(['*' => Http::sequence([
        fakeUniqueQianfanToolCallResponse(),
        $this->fakeQianfanResponse('The number is 72019'),
    ])]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'qianfan',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = collect($followUpBody['messages'])
        ->contains(fn (array $message) => $message['role'] === 'assistant' && isset($message['tool_calls']));

    $hasToolResult = collect($followUpBody['messages'])
        ->contains(fn (array $message) => $message['role'] === 'tool');

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function () {
    Http::fake(['*' => Http::sequence([
        fakeUniqueQianfanToolCallResponse(),
        fakeUniqueQianfanToolCallResponse(),
        fakeUniqueQianfanToolCallResponse(),
        $this->fakeQianfanResponse('Done'),
    ])]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'qianfan',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeUniqueQianfanToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-qianfan-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'ernie-4.5-turbo-128k',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
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
