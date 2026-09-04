<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

test('top level text and usage exclude preliminary sub-agent results', function (): void {
    $response = new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'Hello', time()),
        new ToolResultEvent('event-2', new Data\ToolResult('call-1', 'document_specialist', [], [
            'type' => 'text_delta',
            'delta' => 'internal monologue',
        ]), true, null, time(), preliminaryOutput: 'internal monologue'),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], [
            'type' => 'stream_end',
            'usage' => new Usage(50, 60),
        ]), true, null, time(), preliminaryOutput: 'internal monologue'),
        new TextDelta('event-4', 'message-1', ' world', time()),
        new StreamEnd('event-5', 'stop', new Usage(1, 2), time()),
    ], new Meta('fake', 'model'));

    iterator_to_array($response);

    expect($response->text)->toBe('Hello world')
        ->and($response->usage)->toEqual(new Usage(1, 2))
        ->and($response->events)->toHaveCount(5);
});

test('streamed response aggregates exclude preliminary sub-agent results', function (): void {
    $events = collect([
        new TextDelta('event-1', 'message-1', 'Answer', time()),
        new ToolCallEvent('event-2', new Data\ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], [
            'type' => 'tool_call',
            'tool_id' => 'call-nested',
        ]), true, null, time(), preliminaryOutput: ''),
        new ToolResultEvent('event-4', new Data\ToolResult('call-1', 'document_specialist', [], [
            'type' => 'tool_result',
            'tool_id' => 'call-nested',
        ]), true, null, time(), preliminaryOutput: ''),
        new ToolResultEvent('event-5', new Data\ToolResult('call-1', 'document_specialist', [], [
            'type' => 'tool_approval_request',
            'pending_approvals' => [new PendingApproval('call-inner', 'delete_file', [], 'Needs a human')],
        ]), true, null, time(), preliminaryOutput: ''),
        new ToolResultEvent('event-6', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
        new StreamEnd('event-7', 'stop', new Usage(1, 2), time()),
    ]);

    $response = new StreamedAgentResponse('invocation-1', $events, new Meta('fake', 'model'));

    expect($response->text)->toBe('Answer')
        ->and($response->usage)->toEqual(new Usage(1, 2))
        ->and($response->toolCalls)->toHaveCount(1)
        ->and(collect($response->toolCalls)->first()->id)->toBe('call-1')
        ->and($response->toolResults)->toHaveCount(1)
        ->and(collect($response->toolResults)->first()->id)->toBe('call-1')
        ->and($response->pendingApprovals)->toHaveCount(0)
        ->and($response->events)->toHaveCount(7);
});
