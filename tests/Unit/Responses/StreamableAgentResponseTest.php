<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

test('top level text and usage ignore the output a still running tool reported', function (): void {
    $response = new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'Hello', time()),
        new ToolResultEvent('event-2', new Data\ToolResult('call-1', 'document_specialist', [], 'internal'), true, null, time(), preliminary: true),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], 'internal monologue'), true, null, time(), preliminary: true),
        new TextDelta('event-4', 'message-1', ' world', time()),
        new StreamEnd('event-5', 'stop', new Usage(1, 2), time()),
    ], new Meta('fake', 'model'));

    iterator_to_array($response);

    expect($response->text)->toBe('Hello world')
        ->and($response->usage)->toEqual(new Usage(1, 2));
});

test('streamed response tool aggregates count a tool call once, not its preliminary output', function (): void {
    $events = collect([
        new TextDelta('event-1', 'message-1', 'Answer', time()),
        new ToolCallEvent('event-2', new Data\ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], 'partial'), true, null, time(), preliminary: true),
        new ToolResultEvent('event-4', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
        new StreamEnd('event-5', 'stop', new Usage(1, 2), time()),
    ]);

    $response = new StreamedAgentResponse('invocation-1', $events, new Meta('fake', 'model'));

    expect($response->text)->toBe('Answer')
        ->and(collect($response->toolCalls)->pluck('id')->all())->toBe(['call-1'])
        ->and(collect($response->toolResults)->pluck('id')->all())->toBe(['call-1'])
        ->and($response->pendingApprovals)->toHaveCount(0);
});
