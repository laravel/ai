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

test('pause_turn is ignored when last block is not server_tool_use', function () {
    // Guard: pause_turn should only resume when the last content block is a
    // dangling server_tool_use (matches Anthropic's documented contract).
    // If it fires on any pause_turn we risk spurious continuations and
    // possibly reflecting wrong content back to the server.
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_pause',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                    ['type' => 'text', 'text' => 'Paused here.'],
                ],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('should not be called'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Ask',
        provider: 'anthropic',
    );

    expect(Http::recorded())->toHaveCount(1);
});

test('full compose: pause_turn + server_tool_use replay with input cast and block order preserved', function () {
    // End-to-end unit test exercising PR 1 + PR 2 + PR 3 together.
    // First response: server_tool_use + advisor_tool_result + text with
    // stop_reason pause_turn AND a dangling server_tool_use as the final
    // block. The SDK must replay all blocks in original order, cast the
    // empty server_tool_use.input to {}, and recurse without user
    // intervention.
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_compose',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                    [
                        'type' => 'advisor_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'advisor_result', 'text' => 'Plan.'],
                    ],
                    ['type' => 'text', 'text' => 'Consulting again.'],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_2',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                ],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Compose test',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $payload = json_decode($recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);
    $assistant = collect($payload->messages)->first(fn ($m) => $m->role === 'assistant');

    expect(array_column((array) $assistant->content, 'type'))->toBe([
        'server_tool_use',
        'advisor_tool_result',
        'text',
        'server_tool_use',
    ]);

    $serverBlocks = collect($assistant->content)->where('type', 'server_tool_use')->values();
    expect($serverBlocks)->toHaveCount(2)
        ->and($serverBlocks[0]->input)->toBeInstanceOf(stdClass::class)
        ->and($serverBlocks[1]->input)->toBeInstanceOf(stdClass::class);
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
