<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\NonBroadcastingTextAgent;
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

test('broadcast withholds listed events from the channel but assembles the full response', function () {
    Event::fake();
    NonBroadcastingTextAgent::fake(['Hello world']);

    $received = null;

    (new NonBroadcastingTextAgent)
        ->broadcastNow('Say hello', new Channel('test-channel'))
        ->then(function ($response) use (&$received) {
            $received = $response;
        });

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $e) => $e->broadcastAs() === 'text_delta');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $e) => $e->broadcastAs() === 'stream_start');

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->and($received->text)->toBe('Hello world');
});

test('broadcast agent withholds listed events from the channel but resolves the full response', function () {
    Event::fake();
    NonBroadcastingTextAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new NonBroadcastingTextAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received) {
        $received = $response;
    });

    $job->handle();

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $e) => $e->broadcastAs() === 'text_delta');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $e) => $e->broadcastAs() === 'stream_start');

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->and($received->text)->toBe('Hello world');
});
