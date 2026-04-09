<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            anthropicFakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = $recorded[1][0]->data();

    $hasAssistantWithToolUse = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant') {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $hasAssistantWithToolUse = true;
                }
            }
        }

        if ($message['role'] === 'user') {
            foreach ($message['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $hasToolResult = true;
                }
            }
        }
    }

    expect($hasAssistantWithToolUse)->toBeTrue('Follow-up request should include assistant message with tool_use block');
    expect($hasToolResult)->toBeTrue('Follow-up request should include user message with tool_result block');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            anthropicFakeUniqueToolCallResponse(),
            anthropicFakeUniqueToolCallResponse(),
            anthropicFakeUniqueToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    // ToolUsingAgent has 1 tool + structured output tool = 2 tools
    // maxSteps = round(2 * 1.5) = 3
    // So max 3 requests before stopping (initial + 2 follow-ups)
    expect(count($recorded))->toBeLessThanOrEqual(3);
});

/**
 * Create a tool call response with unique IDs for use in sequences.
 */
function anthropicFakeUniqueToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'msg_tool_'.uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_'.uniqid(),
            'name' => 'FixedNumberGenerator',
            'input' => (object) [],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}
