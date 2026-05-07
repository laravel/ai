<?php

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
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

test('broadcast failures do not fail the queued stream job', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $pendingBroadcast = Mockery::mock();
    $pendingBroadcast->shouldReceive('as')->andReturnSelf();
    $pendingBroadcast->shouldReceive('with')->andReturnSelf();
    $pendingBroadcast->shouldReceive('sendNow')
        ->andThrow(new BroadcastException('Payload too large'));

    Broadcast::shouldReceive('on')
        ->andReturn($pendingBroadcast);

    $received = null;

    $job = new BroadcastAgent(
        agent: new AssistantAgent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received) {
        $received = $response;
    });

    expect(fn () => $job->handle())->not->toThrow(BroadcastException::class);

    expect($received)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});
