<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;

test('then callback receives streamed agent response', function (): void {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('multiple then callbacks all receive streamed agent response', function (): void {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $receivedA = null;
    $receivedB = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$receivedA): void {
        $receivedA = $response;
    });

    $job->then(function ($response) use (&$receivedB): void {
        $receivedB = $response;
    });

    $job->handle();

    expect($receivedA)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($receivedB)->toBeInstanceOf(StreamedAgentResponse::class);
});

test('failed broadcasts a stream_failed event with recoverable false on the configured channel', function (): void {
    Event::fake();

    $channel = new Channel('test-channel');

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: $channel,
    );

    $invocationId = $job->invocationId;
    $job = unserialize(serialize($job));

    $job->failed(new RuntimeException('Something went wrong'));

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use ($channel, $invocationId): bool {
        $payload = $event->broadcastWith();

        return $event->broadcastAs() === 'stream_failed'
            && $payload['invocation_id'] === $invocationId
            && $payload['recoverable'] === false
            && $payload['message'] === 'The stream failed.'
            && $event->broadcastOn() == [$channel];
    });
});

test('failed broadcasts on every channel when given an array', function (): void {
    Event::fake();

    $channels = [new Channel('a'), new Channel('b')];

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: $channels,
    );

    $job->failed(new RuntimeException('boom'));

    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'stream_failed'
        && $event->broadcastOn() === $channels);
});

test('failed event shares the invocation id with broadcasts from handle', function (): void {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->handle();

    $invocationId = $job->invocationId;

    $broadcastIds = [];

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use (&$broadcastIds): true {
        $broadcastIds[] = $event->broadcastWith()['invocation_id'] ?? null;

        return true;
    });

    expect(array_values(array_unique($broadcastIds)))->toBe([$invocationId]);
});

test('an oversized broadcast frame does not abort the stream and then still resolves', function (): void {
    AssistantAgent::fake(['Hello world']);

    $pending = Mockery::mock(AnonymousEvent::class);
    $pending->shouldReceive('as')->andReturnSelf();
    $pending->shouldReceive('with')->andReturnSelf();
    $pending->shouldReceive('sendNow')->andThrow(new BroadcastException('Payload too large'));

    Broadcast::shouldReceive('on')->andReturn($pending);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked despite a failed broadcast')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('streamed response passed to then is fully resolved', function (): void {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->events)->not->toBeEmpty()
        ->and($received->text)->toBe('Hello world');
});
