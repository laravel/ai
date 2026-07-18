<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\NonBroadcastingTextAgent;
use Tests\Fixtures\Agents\NonBroadcastingToolAgent;

test('it rejects event classes that are not stream events', function (): void {
    expect(fn (): WithoutBroadcasting => new WithoutBroadcasting('App\Events\Typo'))
        ->toThrow(InvalidArgumentException::class);
});

test('no events are withheld when the attribute is absent', function (): void {
    expect(WithoutBroadcasting::eventsFor(new AssistantAgent))->toBe([]);
});

test('listed events are withheld when the attribute is present', function (): void {
    expect(WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent))
        ->toContain(ToolResult::class)
        ->toContain(ToolCall::class);
});

test('events not listed in the attribute are not withheld', function (): void {
    expect(WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent))
        ->not->toContain(TextDelta::class);
});

test('a null target withholds nothing', function (): void {
    expect(WithoutBroadcasting::eventsFor(null))->toBe([]);
});

test('broadcast withholds listed events from the channel but assembles the full response', function (): void {
    Event::fake();
    NonBroadcastingTextAgent::fake(['Hello world']);

    $received = null;

    (new NonBroadcastingTextAgent)
        ->broadcastNow('Say hello', new Channel('test-channel'))
        ->then(function ($response) use (&$received): void {
            $received = $response;
        });

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $e): bool => $e->broadcastAs() === 'text_delta');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $e): bool => $e->broadcastAs() === 'stream_start');

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->and($received->text)->toBe('Hello world');
});

test('broadcast agent withholds listed events from the channel but resolves the full response', function (): void {
    Event::fake();
    NonBroadcastingTextAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new NonBroadcastingTextAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $e): bool => $e->broadcastAs() === 'text_delta');
    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $e): bool => $e->broadcastAs() === 'stream_start');

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->and($received->text)->toBe('Hello world');
});
