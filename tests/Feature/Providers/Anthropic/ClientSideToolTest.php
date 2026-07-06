<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ClientSideToolAgent;

test('client-side tool stops the loop without a follow-up request', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_client_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_client_123',
                'name' => 'ClientLocationTool',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new ClientSideToolAgent)->prompt('Where am I?', provider: 'anthropic');

    expect(Http::recorded())->toHaveCount(1);
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls->first())->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls->first()->name)->toBe('ClientLocationTool');
    expect($response->toolResults)->toHaveCount(0);
});

test('client-side tool is not executed server-side', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_client_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_client_123',
                'name' => 'ClientLocationTool',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    expect(fn () => (new ClientSideToolAgent)->prompt('Where am I?', provider: 'anthropic'))
        ->not->toThrow(BadMethodCallException::class);
});

test('assistant message with client-side tool call is included in response messages', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_client_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_client_123',
                'name' => 'ClientLocationTool',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new ClientSideToolAgent)->prompt('Where am I?', provider: 'anthropic');

    $assistantMessage = $response->messages
        ->first(fn ($m) => $m instanceof AssistantMessage);

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->toolCalls)->toHaveCount(1);
    expect($assistantMessage->toolCalls->first()->name)->toBe('ClientLocationTool');
});

test('streaming emits server tool result event when mixed with client-side tools', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: $this->ssePayload([
                $this->messageStart(),
                $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_server_123', 'name' => 'FixedNumberGenerator', 'input' => '']),
                $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['type' => 'tool_use', 'id' => 'toolu_client_123', 'name' => 'ClientLocationTool', 'input' => '']),
                $this->contentBlockDelta(1, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                $this->contentBlockStop(1),
                $this->messageDelta('tool_use', 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents(agent: new ClientSideToolAgent(withServerTool: true));

    $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

    // Both tool calls are streamed
    expect($toolCallEvents)->toHaveCount(2);

    // Server tool result is streamed so the client can reconstruct history for the next turn
    expect($toolResultEvents)->toHaveCount(1);
    expect($toolResultEvents[0]->toolResult->name)->toBe('FixedNumberGenerator');
});

test('server tools are executed before client-side tool ends the turn', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_mixed_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_server_123',
                    'name' => 'FixedNumberGenerator',
                    'input' => (object) [],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_client_123',
                    'name' => 'ClientLocationTool',
                    'input' => (object) [],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new ClientSideToolAgent(withServerTool: true))->prompt(
        'Get a number and my location',
        provider: 'anthropic',
    );

    // Only one HTTP request — no follow-up despite server tool executing
    expect(Http::recorded())->toHaveCount(1);

    // Both tool calls present
    expect($response->toolCalls)->toHaveCount(2);

    // Only the server tool has a result
    expect($response->toolResults)->toHaveCount(1);
    expect($response->toolResults->first()->name)->toBe('FixedNumberGenerator');
});
