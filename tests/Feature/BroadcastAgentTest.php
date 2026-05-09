<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;

test('then callback receives streamed agent response', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received) {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('multiple then callbacks all receive streamed agent response', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $receivedA = null;
    $receivedB = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$receivedA) {
        $receivedA = $response;
    });

    $job->then(function ($response) use (&$receivedB) {
        $receivedB = $response;
    });

    $job->handle();

    expect($receivedA)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($receivedB)->toBeInstanceOf(StreamedAgentResponse::class);
});

test('failed broadcasts a stream_failed event with recoverable false on the configured channel', function () {
    Event::fake();

    $channel = new Channel('test-channel');

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: $channel,
    );

    $job->failed(new RuntimeException('Something went wrong'));

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use ($channel) {
        $payload = $event->broadcastWith();

        return $event->broadcastAs() === 'stream_failed'
            && $payload['recoverable'] === false
            && $payload['message'] === RuntimeException::class
            && $event->broadcastOn() === [$channel];
    });
});

test('failed broadcasts on every channel when given an array', function () {
    Event::fake();

    $channels = [new Channel('a'), new Channel('b')];

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: $channels,
    );

    $job->failed(new RuntimeException('boom'));

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use ($channels) {
        return $event->broadcastAs() === 'stream_failed'
            && $event->broadcastOn() === $channels;
    });
});

test('failed event shares the invocation id with broadcasts from handle', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->handle();

    $invocationId = $job->invocationId;
    expect($invocationId)->not->toBeNull();

    $job->failed(new RuntimeException('boom'));

    $broadcastIds = [];

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use (&$broadcastIds) {
        $broadcastIds[] = $event->broadcastWith()['invocation_id'] ?? null;

        return true;
    });

    expect(array_unique($broadcastIds))->toBe([$invocationId]);
});

test('streamed response passed to then is fully resolved', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received) {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->events)->not->toBeEmpty()
        ->and($received->text)->toBe('Hello world');
});
