<?php

use Laravel\Ai\Gateway\Prism\PrismStreamEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\ValueObjects\Usage;

test('thinking complete event maps to reasoning end', function () {
    $event = new ThinkingCompleteEvent(
        id: 'event-1',
        timestamp: 1234567890,
        reasoningId: 'reasoning-1',
        summary: ['text' => 'Summary of reasoning'],
    );

    $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

    expect($result)->toBeInstanceOf(ReasoningEnd::class);
    expect($result->id)->toEqual('event-1');
    expect($result->reasoningId)->toEqual('reasoning-1');
    expect($result->timestamp)->toEqual(1234567890);
    expect($result->summary)->toEqual(['text' => 'Summary of reasoning']);
});

test('thinking complete event handles null summary', function () {
    $event = new ThinkingCompleteEvent(
        id: 'event-2',
        timestamp: 1234567890,
        reasoningId: 'reasoning-2',
    );

    $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

    expect($result)->toBeInstanceOf(ReasoningEnd::class);
    expect($result->summary)->toBeNull();
});

test('stream end event handles null usage', function () {
    $event = new StreamEndEvent(
        id: 'event-3',
        timestamp: 1234567890,
        finishReason: FinishReason::Stop,
        usage: null,
    );

    $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openrouter', 'anthropic/claude-sonnet');

    expect($result)->toBeInstanceOf(StreamEnd::class);
    expect($result->usage->promptTokens)->toEqual(0);
    expect($result->usage->completionTokens)->toEqual(0);
});

test('stream end event maps usage', function () {
    $event = new StreamEndEvent(
        id: 'event-4',
        timestamp: 1234567890,
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 100, completionTokens: 50),
    );

    $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openrouter', 'anthropic/claude-sonnet');

    expect($result)->toBeInstanceOf(StreamEnd::class);
    expect($result->usage->promptTokens)->toEqual(100);
    expect($result->usage->completionTokens)->toEqual(50);
});

test('error event maps correctly', function () {
    $event = new ErrorEvent(
        id: 'event-5',
        timestamp: 1234567890,
        errorType: 'server_error',
        message: 'Something went wrong',
        recoverable: false,
        metadata: ['foo' => 'bar'],
    );

    $result = PrismStreamEvent::toLaravelStreamEvent('invocation-1', $event, 'openai', 'gpt-4');

    expect($result)->toBeInstanceOf(Error::class);

    /** @var Error $result */
    expect($result->id)->toEqual('event-5');
    expect($result->type)->toEqual('server_error');
    expect($result->message)->toEqual('Something went wrong');
    expect($result->recoverable)->toBeFalse();
    expect($result->timestamp)->toEqual(1234567890);
    expect($result->metadata)->toEqual(['foo' => 'bar']);
});
