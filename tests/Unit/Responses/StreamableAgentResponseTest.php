<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

test('finally callbacks run when stream iteration completes', function () {
    $released = false;

    $response = new StreamableAgentResponse('inv-1', function () {
        yield new TextDelta('e1', 'inv-1', 'hi', time());
    }, new Meta);

    $response->finally(function () use (&$released) {
        $released = true;
    });

    foreach ($response as $event) {
        // consume
    }

    expect($released)->toBeTrue();
});

test('finally callbacks run even when stream iteration throws mid-flight', function () {
    $released = false;

    $response = new StreamableAgentResponse('inv-1', function () {
        yield new TextDelta('e1', 'inv-1', 'hi', time());

        throw new RuntimeException('boom');
    });

    $response->finally(function () use (&$released) {
        $released = true;
    });

    try {
        foreach ($response as $event) {
            // consume until the generator throws
        }
    } catch (RuntimeException) {
        // expected
    }

    // The pause lock release rides on this callback, so it must fire on failure too...
    expect($released)->toBeTrue();
});
