<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\ToolResult;

test('a preliminary result flags itself while keeping the identity of the tool call it belongs to', function (): void {
    $event = (new ToolResult(
        'event-1',
        new Data\ToolResult('call-1', 'research_agent', ['task' => 'Research'], 'Working...'),
        true,
        null,
        100,
        preliminary: true,
    ))->withInvocationId('parent-invocation');

    expect($event->toArray())->toBe([
        'id' => 'event-1',
        'invocation_id' => 'parent-invocation',
        'type' => 'tool_result',
        'tool_id' => 'call-1',
        'tool_name' => 'research_agent',
        'result' => 'Working...',
        'successful' => true,
        'error' => null,
        'denied' => false,
        'preliminary' => true,
        'timestamp' => 100,
    ]);
});

test('a settled result says nothing about being preliminary', function (): void {
    $event = new ToolResult(
        'event-1',
        new Data\ToolResult('call-1', 'research_agent', [], 'done'),
        true,
        null,
        100,
    );

    expect($event->toArray())->not->toHaveKey('preliminary');
});
