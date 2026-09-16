<?php

use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

function reasoningDelta(string $reasoningId, string $delta): ReasoningDelta
{
    return new ReasoningDelta(uniqid(), $reasoningId, $delta, time());
}

test('combine joins deltas of a single reasoning block without separators', function () {
    $events = [
        reasoningDelta('reasoning-1', 'The user'),
        reasoningDelta('reasoning-1', ' wants'),
        reasoningDelta('reasoning-1', ' the weather.'),
    ];

    expect(ReasoningDelta::combine($events))->toBe('The user wants the weather.');
});

test('combine separates reasoning from different steps with a blank line', function () {
    $events = [
        reasoningDelta('reasoning-1', 'I should look this up.'),
        reasoningDelta('reasoning-2', 'The tool answered, so I can reply.'),
    ];

    expect(ReasoningDelta::combine($events))->toBe("I should look this up.\n\nThe tool answered, so I can reply.");
});

test('combine drops whitespace-only reasoning blocks', function () {
    $events = [
        reasoningDelta('reasoning-1', 'First.'),
        reasoningDelta('reasoning-2', "\n"),
        reasoningDelta('reasoning-3', 'Second.'),
    ];

    expect(ReasoningDelta::combine($events))->toBe("First.\n\nSecond.");
});

test('combine ignores events that are not reasoning deltas', function () {
    $events = [
        new StreamStart(uniqid(), 'fake', 'fake-model', time()),
        new TextDelta(uniqid(), 'message-1', 'The answer.', time()),
        reasoningDelta('reasoning-1', 'Only reasoning.'),
    ];

    expect(ReasoningDelta::combine($events))->toBe('Only reasoning.');
});

test('combine returns an empty string when there are no reasoning deltas', function () {
    expect(ReasoningDelta::combine([]))->toBe('');
});
