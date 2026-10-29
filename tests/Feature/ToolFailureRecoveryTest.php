<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Exceptions\ToolHandlerException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Agents\ThrowingToolAgent;

test('a throwing tool returns a failed result to the model', function (): void {
    Exceptions::fake();

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $response = (new ThrowingToolAgent)->prompt('Generate');

    expect($response->text)->toBe('The tool could not run.')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->successful())->toBeFalse()
        ->and($response->toolResults[0]->error())->toBe('The tool call failed: Forced to throw exception.');

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});

test('a throwing tool streams a failed result', function (): void {
    Exceptions::fake();

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $events = iterator_to_array((new ThrowingToolAgent)->stream('Generate'), false);

    $toolResults = array_values(array_filter($events, fn ($event): bool => $event instanceof ToolResult));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->successful)->toBeFalse()
        ->and($toolResults[0]->error)->toBe('The tool call failed: Forced to throw exception.')
        ->and(TextDelta::combine(array_filter($events, fn ($event): bool => $event instanceof TextDelta)))->toBe('The tool could not run.');

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});

test('an exception from a tool event listener still fails the run', function (): void {
    Exceptions::fake();

    Event::listen(InvokingTool::class, fn () => throw new RuntimeException('Listener failed.'));

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Never reached.',
    ]);

    expect(fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'))
        ->toThrow(RuntimeException::class, 'Listener failed.');

    Exceptions::assertNothingReported();
});

test('a tool failed listener that rethrows fails the run with the tool exception', function (): void {
    Exceptions::fake();

    Event::listen(ToolFailed::class, fn (ToolFailed $event) => throw $event->exception);

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Never reached.',
    ]);

    expect(fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'))
        ->toThrow(fn (Exception $exception) => expect($exception)->not->toBeInstanceOf(ToolHandlerException::class)
            ->and($exception->getMessage())->toBe('Forced to throw exception.'));

    Exceptions::assertNothingReported();
});
