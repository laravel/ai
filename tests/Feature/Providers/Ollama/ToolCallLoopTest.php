<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeOllamaTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
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

test('tool result message uses tool_name field', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeOllamaTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->first(fn ($m) => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg)->not->toHaveKey('tool_call_id');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            fakeOllamaTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    // ToolUsingAgent has 1 tool + structured output tool = 2 tools
    // maxSteps = round(2 * 1.5) = 3
    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeOllamaTextResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => $text,
        ],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 1,
        'eval_count' => 1,
    ]);
}

function fakeUniqueOllamaToolCallResponse(): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_'.uniqid(),
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
