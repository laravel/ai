<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function vercelProtocolParts(array $events, ?string $messageId = null): array
{
    $response = (new StreamableAgentResponse('invocation-1', fn () => yield from $events, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingVercelDataProtocol(messageId: $messageId)
        ->toResponse(request());

    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    return collect(explode("\n\n", trim($output)))
        ->map(fn (string $frame) => str_replace('data: ', '', $frame))
        ->map(fn (string $payload) => $payload === '[DONE]' ? ['type' => 'done'] : json_decode($payload, true))
        ->all();
}

test('a text stream emits start, delta, and end parts for the message', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello.', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hello.'],
        ['type' => 'text-end', 'id' => 'msg-1'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a reasoning stream emits start, delta, and end parts for the reasoning block', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ReasoningStart('event-1', 'reasoning-1', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
        new ReasoningEnd('event-3', 'reasoning-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'reasoning-start', 'id' => 'reasoning-1'],
        ['type' => 'reasoning-delta', 'id' => 'reasoning-1', 'delta' => 'Considering the options.'],
        ['type' => 'reasoning-end', 'id' => 'reasoning-1'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a paused stream emits an approval request part for each pending approval', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
        ['type' => 'tool-approval-request', 'toolCallId' => 'call-1', 'approvalId' => 'call-1', 'reason' => 'Destructive operation.'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a resumed stream emits the approved tool output for the prior turn tool call', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'],
        ['type' => 'text-delta', 'id' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a resumed stream may continue an existing client-side message', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts[0])->toBe(['type' => 'start', 'messageId' => 'client-message-1'])
        ->and(collect($parts)->where('type', 'start'))->toHaveCount(1);
});

test('a rejected approval streams as a denied tool output', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'tool-output-denied', 'toolCallId' => 'call-1'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a tool result without a prior call or existing message is skipped', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('an unexecuted tool call streams as a tool output error', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The agent reached its maximum number of steps without running this tool call.'), false, 'The agent reached its maximum number of steps without running this tool call.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts[2])->toBe([
        'type' => 'tool-output-error',
        'toolCallId' => 'call-1',
        'errorText' => 'The agent reached its maximum number of steps without running this tool call.',
    ]);
});

test('a tool executed within the stream emits its input and output parts', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'GetWeather', 'input' => ['city' => 'Lisbon']],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'sunny'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});
