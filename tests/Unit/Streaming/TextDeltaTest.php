<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function textDelta(string $messageId, string $delta): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $delta, time());
}

/**
 * Build the stream start that every gateway yields exactly once at the top of a step.
 */
function stepStart(): StreamStart
{
    return new StreamStart(uniqid(), 'fake', 'fake-model', time());
}

test('combine joins the deltas of a single step without separators', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'Hello'),
        textDelta('message-1', ' there'),
        textDelta('message-1', '!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine separates the text of different steps with a blank line', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'Let me look that up.'),
        new ToolCall(uniqid(), new Data\ToolCall('call-1', 'get_weather', ['city' => 'Copenhagen']), time()),
        new ToolResult(uniqid(), new Data\ToolResult('call-1', 'get_weather', ['city' => 'Copenhagen'], '12°C'), true, null, time()),
        stepStart(),
        textDelta('message-2', 'It is '),
        textDelta('message-2', '12°C in Copenhagen.'),
    ];

    expect(TextDelta::combine($events))->toBe("Let me look that up.\n\nIt is 12°C in Copenhagen.");
});

test('combine keeps a step whole when a provider splits it around a citation', function () {
    // Anthropic opens a new message ID per content block, and web search splits one answer across several of them. A live run returned seven IDs in a single step, breaking mid-sentence; grouping by ID gave each fragment a paragraph of its own, and a lone full stop after...
    $events = [
        stepStart(),
        textDelta('message-1', 'Laravel 13 is current, which'),
        textDelta('message-2', ' shipped in March'),
        textDelta('message-3', '.'),
    ];

    expect(TextDelta::combine($events))->toBe('Laravel 13 is current, which shipped in March.');
});

test('combine drops a step that produced only whitespace', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'First.'),
        stepStart(),
        textDelta('message-2', "\n"),
        stepStart(),
        textDelta('message-3', 'Second.'),
    ];

    expect(TextDelta::combine($events))->toBe("First.\n\nSecond.");
});

test('combine ignores events that are not text deltas', function () {
    $events = [
        stepStart(),
        new TextStart(uniqid(), 'message-1', time()),
        textDelta('message-1', 'Only text.'),
        new TextEnd(uniqid(), 'message-1', time()),
    ];

    expect(TextDelta::combine($events))->toBe('Only text.');
});

test('combine joins deltas that arrive before any step start', function () {
    // A stream that never announced a step still has to yield its text rather than drop it...
    $events = [
        textDelta('message-1', 'Hello'),
        textDelta('message-2', ' there!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine returns an empty string when there are no text deltas', function () {
    expect(TextDelta::combine([]))->toBe('');
});
