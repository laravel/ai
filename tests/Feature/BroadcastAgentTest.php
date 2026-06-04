<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\InvocationContext;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;

afterEach(function () {
    InvocationContext::flush();

    unset($_SERVER['__testing.broadcast-prompt']);
});

test('broadcastOnQueue forwards the active invocation context to the job', function () {
    Queue::fake();

    InvocationContext::run(InvocationContext::root('parent-inv'), function () {
        (new AssistantAgent)->broadcastOnQueue('Do it', new Channel('test-channel'));
    });

    Queue::assertPushed(BroadcastAgent::class, function (BroadcastAgent $job) {
        return $job->parentInvocationId === 'parent-inv'
            && $job->rootInvocationId === 'parent-inv';
    });
});

test('broadcastOnQueue outside any invocation forwards no lineage', function () {
    Queue::fake();

    (new AssistantAgent)->broadcastOnQueue('Do it', new Channel('test-channel'));

    Queue::assertPushed(BroadcastAgent::class, function (BroadcastAgent $job) {
        return $job->parentInvocationId === null
            && $job->rootInvocationId === null;
    });
});

test('a queued broadcast re-establishes the dispatching context so its stream nests beneath it', function () {
    Event::fake();
    AssistantAgent::fake(['Hello world']);

    $agent = (new AssistantAgent)->withMiddleware([new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.broadcast-prompt'] = $prompt;

            return $next($prompt);
        }
    }]);

    $job = new BroadcastAgent(
        agent: $agent,
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
        parentInvocationId: 'parent-inv',
        rootInvocationId: 'root-inv',
    );

    $job->handle();

    $prompt = $_SERVER['__testing.broadcast-prompt'];

    expect($prompt->invocationId)->not->toBe('parent-inv')
        ->and($prompt->parentInvocationId)->toBe('parent-inv')
        ->and($prompt->rootInvocationId)->toBe('root-inv');
});

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

    $invocationId = $job->invocationId;
    $job = unserialize(serialize($job));

    $job->failed(new RuntimeException('Something went wrong'));

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use ($channel, $invocationId) {
        $payload = $event->broadcastWith();

        return $event->broadcastAs() === 'stream_failed'
            && $payload['invocation_id'] === $invocationId
            && $payload['recoverable'] === false
            && $payload['message'] === 'The stream failed.'
            && $event->broadcastOn() == [$channel];
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

    $broadcastIds = [];

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use (&$broadcastIds) {
        $broadcastIds[] = $event->broadcastWith()['invocation_id'] ?? null;

        return true;
    });

    expect(array_values(array_unique($broadcastIds)))->toBe([$invocationId]);
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
