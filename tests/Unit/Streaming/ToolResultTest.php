<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\ToolResult;

test('preliminary sub-agent results retain their tool identity', function (): void {
    $event = (new ToolResult(
        'event-1',
        new Data\ToolResult('call-1', 'research_agent', ['task' => 'Research'], [
            'type' => 'text_delta',
            'delta' => 'Working...',
        ]),
        true,
        null,
        100,
        preliminaryOutput: 'Working...',
    ))->withInvocationId('parent-invocation');

    expect($event->toArray())->toMatchArray([
        'type' => 'tool_result',
        'tool_id' => 'call-1',
        'preliminary' => true,
    ]);
});
