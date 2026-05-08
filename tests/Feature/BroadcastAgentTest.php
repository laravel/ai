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

test('failed broadcasts a stream_failed event with recoverable false', function () {
    Event::fake();

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->failed(new RuntimeException('Something went wrong'));

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) {
        return $event->broadcastAs() === 'stream_failed'
            && $event->broadcastWith()['recoverable'] === false
            && $event->broadcastWith()['message'] === 'The agent stream failed.';
    });
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
