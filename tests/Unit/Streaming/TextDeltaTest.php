<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function textDelta(string $messageId, string $delta): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $delta, time());
}

test('combine joins deltas of a single message without separators', function () {
    $events = [
        textDelta('message-1', 'Hello'),
        textDelta('message-1', ' there'),
        textDelta('message-1', '!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine separates text from different messages with a blank line', function () {
    $events = [
        textDelta('message-1', 'Let me look that up.'),
        new ToolCall(uniqid(), new Data\ToolCall('call-1', 'get_weather', ['city' => 'Copenhagen']), time()),
        new ToolResult(uniqid(), new Data\ToolResult('call-1', 'get_weather', ['city' => 'Copenhagen'], '12°C'), true, null, time()),
        textDelta('message-2', 'It is '),
        textDelta('message-2', '12°C in Copenhagen.'),
    ];

    expect(TextDelta::combine($events))->toBe("Let me look that up.\n\nIt is 12°C in Copenhagen.");
});

test('combine drops whitespace-only messages', function () {
    $events = [
        textDelta('message-1', 'First.'),
        textDelta('message-2', "\n"),
        textDelta('message-3', 'Second.'),
    ];

    expect(TextDelta::combine($events))->toBe("First.\n\nSecond.");
});

test('combine ignores events that are not text deltas', function () {
    $events = [
        new StreamStart(uniqid(), 'fake', 'fake-model', time()),
        textDelta('message-1', 'Only text.'),
    ];

    expect(TextDelta::combine($events))->toBe('Only text.');
});

test('combine returns an empty string when there are no text deltas', function () {
    expect(TextDelta::combine([]))->toBe('');
});
