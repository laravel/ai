<?php

use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\NonBroadcastingToolAgent;

function toolResultEvent(): ToolResult
{
    return new ToolResult(
        id: 'event-id',
        toolResult: new Data\ToolResult('tool-id', 'search', [], str_repeat('x', 15_000)),
        successful: true,
        error: null,
        timestamp: time(),
    );
}

function toolCallEvent(): ToolCall
{
    return new ToolCall(
        id: 'event-id',
        toolCall: new Data\ToolCall('tool-id', 'search', []),
        timestamp: time(),
    );
}

function textDeltaEvent(): TextDelta
{
    return new TextDelta('event-id', 'message-id', 'Hello', time());
}

test('all events are broadcast when the attribute is absent', function () {
    $agent = new AssistantAgent;

    expect(WithoutBroadcasting::allows($agent, toolResultEvent()))->toBeTrue()
        ->and(WithoutBroadcasting::allows($agent, toolCallEvent()))->toBeTrue()
        ->and(WithoutBroadcasting::allows($agent, textDeltaEvent()))->toBeTrue();
});

test('listed events are not broadcast when the attribute is present', function () {
    $agent = new NonBroadcastingToolAgent;

    expect(WithoutBroadcasting::allows($agent, toolResultEvent()))->toBeFalse()
        ->and(WithoutBroadcasting::allows($agent, toolCallEvent()))->toBeFalse();
});

test('events not listed in the attribute are still broadcast', function () {
    $agent = new NonBroadcastingToolAgent;

    expect(WithoutBroadcasting::allows($agent, textDeltaEvent()))->toBeTrue();
});

test('a null target broadcasts all events', function () {
    expect(WithoutBroadcasting::allows(null, toolResultEvent()))->toBeTrue();
});
