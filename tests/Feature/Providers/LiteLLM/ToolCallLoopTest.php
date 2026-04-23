<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.litellm' => [
        ...config('ai.providers.litellm'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueLiteLLMToolCallResponse(),
            fakeLiteLLMResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'litellm',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueLiteLLMToolCallResponse(),
            fakeUniqueLiteLLMToolCallResponse(),
            fakeUniqueLiteLLMToolCallResponse(),
            fakeLiteLLMResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'litellm',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeUniqueLiteLLMToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'openai/gpt-4o',
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
