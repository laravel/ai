<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function vercelProtocolParts(array $events): array
{
    $response = (new StreamableAgentResponse('invocation-1', fn () => yield from $events, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingVercelDataProtocol()
        ->toResponse(request());

    $output = '';

    // Accumulate flushed chunks, since the streamed response flushes the buffer as it goes...
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
        ['type' => 'tool-approval-request', 'toolCallId' => 'call-1', 'approvalId' => 'call-1'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a resumed stream replays the approved tool input before its output', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'invocation-1'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'],
        ['type' => 'text-delta', 'id' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a rejected approval streams as a tool output error', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Tool call rejected by approver.'), false, 'Tool call rejected by approver.', time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'invocation-1'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
        ['type' => 'tool-output-error', 'toolCallId' => 'call-1', 'errorText' => 'Tool call rejected by approver.'],
        ['type' => 'finish'],
        ['type' => 'done'],
    ]);
});

test('a tool executed within the stream does not replay its input', function () {
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
