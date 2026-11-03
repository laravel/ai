<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;

test('a provider error broadcasts under a fixed name and keeps its code in the payload', function (string $code) {
    Event::fake();

    (new Error('error-1', $code, 'The caller does not have permission', false, time()))
        ->broadcastNow(new Channel('test-channel'));

    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'error'
        && $event->broadcastWith()['type'] === $code);
})->with(['403', 'PERMISSION_DENIED', 'unknown_error']);

test('other stream events still broadcast under their type', function () {
    Event::fake();

    (new TextDelta('delta-1', 'message-1', 'Hello', time()))->broadcastNow(new Channel('test-channel'));

    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'text_delta');
});
