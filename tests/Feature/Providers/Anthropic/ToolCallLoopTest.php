<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

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
    // First response: tool_use (triggers loop) alongside a server_tool_use
    // with empty input — simulating e.g. the advisor tool, whose input is
    // documented as always empty. Anthropic's API requires `input` to be a
    // JSON object ({}). PHP's json_decode('{}', true) produces [], which
    // re-encodes as JSON array [] — causing 400 invalid_request_error on
    // replay unless ensureToolInputIsObject() covers server_tool_use too.
    $responseWithServerTool = Http::response([
        'id' => 'msg_tool_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            [
                'type' => 'server_tool_use',
                'id' => 'srvtoolu_123',
                'name' => 'advisor',
                'input' => (object) [],
            ],
            [
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'FixedNumberGenerator',
                'input' => (object) [],
            ],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $responseWithServerTool,
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $payload = json_decode($recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);

    $assistant = collect($payload->messages)->first(fn ($message) => $message->role === 'assistant');
    $serverToolUse = collect($assistant->content)->first(fn ($block) => $block->type === 'server_tool_use');

    expect($serverToolUse)->not->toBeNull()
        ->and($serverToolUse->input)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($serverToolUse->input))->toBeEmpty();
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
