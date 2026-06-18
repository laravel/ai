<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('tool-loop follow-up uses the original request model, not the response model', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'groq',
        model: 'llama-3.3-70b',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp['model'])->toBe('llama-3.3-70b')
        ->and($followUp['model'])->not->toBe('openai/gpt-oss-20b')
        ->and($response->meta->model)->toBe('openai/gpt-oss-20b');
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'groq',
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
        'api.groq.com/*' => Http::sequence([
            fakeUniqueGroqToolCallResponse(),
            fakeUniqueGroqToolCallResponse(),
            fakeUniqueGroqToolCallResponse(),
            fakeGroqResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'groq',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

function fakeUniqueGroqToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
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
