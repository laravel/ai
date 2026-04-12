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

test('pause_turn stop reason triggers follow-up request replaying assistant content', function () {
    // A dangling server_tool_use (e.g. advisor) can produce stop_reason:
    // pause_turn, which means the server-side loop needs the client to send
    // the assistant response back verbatim so the server can continue.
    // Without handling, the conversation terminates prematurely.
    $pauseTurnResponse = Http::response([
        'id' => 'msg_pause',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'text', 'text' => 'Consulting advisor.'],
            [
                'type' => 'server_tool_use',
                'id' => 'srvtoolu_pause',
                'name' => 'advisor',
                'input' => (object) [],
            ],
        ],
        'stop_reason' => 'pause_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $pauseTurnResponse,
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Help me plan something',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $payload = json_decode($recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);

    $messages = $payload->messages;
    $lastMessage = end($messages);

    expect($lastMessage->role)->toBe('assistant')
        ->and(array_column((array) $lastMessage->content, 'type'))->toBe(['text', 'server_tool_use']);

    $serverToolUse = collect($lastMessage->content)->firstWhere('type', 'server_tool_use');
    expect($serverToolUse->input)->toBeInstanceOf(stdClass::class);
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

    expect(count($recorded))->toBeLessThanOrEqual(3);
});
