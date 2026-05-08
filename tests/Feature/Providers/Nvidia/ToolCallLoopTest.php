<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.nvidia' => [
        ...config('ai.providers.nvidia'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'integrate.api.nvidia.com/*' => Http::sequence([
            fakeUniqueNvidiaToolCallResponse(),
            fakeNvidiaResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'nvidia',
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
        'integrate.api.nvidia.com/*' => Http::sequence([
            fakeUniqueNvidiaToolCallResponse(),
            fakeUniqueNvidiaToolCallResponse(),
            fakeUniqueNvidiaToolCallResponse(),
            fakeNvidiaResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'nvidia',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeUniqueNvidiaToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-nv-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'meta/llama-3.3-70b-instruct',
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
