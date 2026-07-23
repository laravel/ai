<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Jobs\BroadcastStaticMessage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;

test('a static message broadcasts a complete stream without prompting the agent', function (): void {
    Event::fake();
    AssistantAgent::fake()->preventStrayPrompts();

    $channel = new Channel('announcements');

    (new AssistantAgent)->broadcastMessageNow('Welcome aboard!', $channel);

    AssistantAgent::assertNeverPrompted();

    $events = collect(Event::dispatched(AnonymousEvent::class))
        ->map(fn (array $arguments): AnonymousEvent => $arguments[0]);
    $payloads = $events->map(fn (AnonymousEvent $event): array => $event->broadcastWith());

    expect($events->map(fn (AnonymousEvent $event): string => $event->broadcastAs())->all())
        ->toBe(['stream_start', 'text_start', 'text_delta', 'text_end', 'stream_end'])
        ->and($events->every(fn (AnonymousEvent $event): bool => $event->broadcastOn() === [$channel]))->toBeTrue()
        ->and($payloads->pluck('invocation_id')->unique())->toHaveCount(1)
        ->and($payloads[0]['provider'])->toBe('static')
        ->and($payloads[0]['model'])->toBe('static')
        ->and($payloads[2]['delta'])->toBe('Welcome aboard!')
        ->and($payloads[4]['reason'])->toBe('stop')
        ->and($payloads[4]['usage'])->toBe([
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
        ]);
});

test('a static message can be queued for broadcasting', function (): void {
    Queue::fake();

    $channel = new Channel('announcements');

    $response = (new AssistantAgent)->broadcastMessageOnQueue('Scheduled maintenance.', $channel);

    expect($response)->toBeInstanceOf(QueuedAgentResponse::class);

    unset($response);

    Queue::assertPushed(BroadcastStaticMessage::class, fn (BroadcastStaticMessage $job): bool => $job->message === 'Scheduled maintenance.'
        && $job->channels === $channel);
});

test('the queued static message broadcasts without prompting the agent', function (): void {
    Event::fake();
    AssistantAgent::fake()->preventStrayPrompts();

    $received = null;

    $job = new BroadcastStaticMessage(
        agent: new AssistantAgent,
        message: 'Scheduled maintenance.',
        channels: new Channel('announcements'),
    );

    $job->then(function (string $message) use (&$received): void {
        $received = $message;
    });

    $job->handle();

    AssistantAgent::assertNeverPrompted();

    expect($received)->toBe('Scheduled maintenance.');

    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'text_delta'
        && $event->broadcastWith()['delta'] === 'Scheduled maintenance.');
});
