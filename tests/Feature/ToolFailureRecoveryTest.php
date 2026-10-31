<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Exceptions\ToolExecutionException;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Vercel\Vercel;
use Tests\Fixtures\Agents\RememberingFailingToolAgent;
use Tests\Fixtures\Agents\ThrowingToolAgent;

function toolFailureStreamedBody(StreamableAgentResponse $response): string
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->toResponse(request())->sendContent();

    ob_end_clean();

    return $output;
}

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
        ->toThrow(fn (Exception $exception) => expect($exception)->not->toBeInstanceOf(ToolExecutionException::class)
            ->and($exception->getMessage())->toBe('Forced to throw exception.'));

    Exceptions::assertNothingReported();
});

test('a throwing tool streams to the client without its exception message', function (Closure $protocol): void {
    Exceptions::fake();

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $toolResults = null;

    $response = $protocol((new ThrowingToolAgent)->stream('Generate'))
        ->then(function (StreamedAgentResponse $response) use (&$toolResults): void {
            $toolResults = $response->toolResults;
        });

    expect(toolFailureStreamedBody($response))
        ->toContain('The tool call failed.')
        ->not->toContain('Forced to throw exception.')
        ->and($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->result)->toBe('The tool call failed: Forced to throw exception.');
})->with([
    'vercel' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingVercelDataProtocol()],
    'ag-ui' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response->usingAgentUserInteractionProtocol()],
    'server-sent events' => [fn (StreamableAgentResponse $response): StreamableAgentResponse => $response],
]);

test('a throwing tool broadcasts its result without the exception message', function (): void {
    Event::fake();
    Exceptions::fake();

    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $toolResults = null;

    (new ThrowingToolAgent)
        ->broadcastNow('Generate', new Channel('test-channel'))
        ->then(function (StreamedAgentResponse $response) use (&$toolResults): void {
            $toolResults = $response->toolResults;
        });

    Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'tool_result'
        && $event->broadcastWith()['result'] === 'The tool call failed.'
        && $event->broadcastWith()['error'] === 'The tool call failed.');

    expect($toolResults[0]->result)->toBe('The tool call failed: Forced to throw exception.');
});

test('a stored throwing tool call replays to the client without its exception message', function (Closure $render, string $part): void {
    Config::set('ai.conversations.generate_title', false);
    Exceptions::fake();

    RememberingFailingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    (new RememberingFailingToolAgent)->forUser((object) ['id' => 1])->prompt('Generate');

    $replayed = (string) json_encode($render(ConversationMessage::query()->orderBy('created_at')->orderBy('id')->get()));

    expect($replayed)
        ->toContain($part)
        ->not->toContain('Forced to throw exception.')
        ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'))
        ->toContain('The tool call failed: Forced to throw exception.');
})->with([
    'vercel' => [fn ($messages): array => Vercel::toUiMessages($messages), '"state":"output-error","input":[],"errorText":"The tool call failed."'],
    'ag-ui' => [fn ($messages): array => AgentUserInteraction::toClientState($messages), '"content":"The tool call failed."'],
]);
