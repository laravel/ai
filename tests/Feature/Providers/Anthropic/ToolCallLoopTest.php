<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('tool calls trigger follow up request', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
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

    expect($hasAssistantWithToolUse)->toBeTrue('Follow-up request should include assistant message with tool_use block')
        ->and($hasToolResult)->toBeTrue('Follow-up request should include user message with tool_result block');
});

test('server_tool_use input is serialized as object on follow-up replay', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_123', 'name' => 'advisor', 'input' => (object) []],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

    $followUpBody = Http::recorded()[1][0]->body();

    expect($followUpBody)
        ->toContain('"type":"server_tool_use"')
        ->not->toContain('"input":[]');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
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
