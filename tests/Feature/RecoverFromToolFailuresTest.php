<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\ToolHandlerException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tests\Fixtures\Agents\RecoveringThrowingApprovableAgent;
use Tests\Fixtures\Agents\RecoveringThrowingToolAgent;
use Tests\Fixtures\Agents\RememberingThrowingApprovableAgent;
use Tests\Fixtures\Agents\ThrowingToolAgent;

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.groq', ['driver' => 'groq', 'key' => 'test-key']);
});

test('a throwing tool fails the run without the recovery attribute', function (): void {
    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Never reached.',
    ]);

    // The tool's own exception reaches the caller, never the wrapper the loop uses to recognize it...
    expect(fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'))
        ->toThrow(fn (Exception $exception) => expect($exception)->not->toBeInstanceOf(ToolHandlerException::class)
            ->and($exception->getMessage())->toBe('Forced to throw exception.'));
});

test('a throwing tool fails a streamed run without the recovery attribute', function (): void {
    ThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Never reached.',
    ]);

    expect(fn (): array => iterator_to_array((new ThrowingToolAgent)->stream('Generate'), false))
        ->toThrow(Exception::class, 'Forced to throw exception.');
});

test('a throwing tool returns a failed result to the model for agents with the recovery attribute', function (): void {
    Exceptions::fake();

    RecoveringThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $response = (new RecoveringThrowingToolAgent)->prompt('Generate');

    expect($response->text)->toBe('The tool could not run.')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->successful())->toBeFalse()
        ->and($response->toolResults[0]->error())->toBe('The tool call failed: Forced to throw exception.');

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});

test('a throwing tool streams a failed result for agents with the recovery attribute', function (): void {
    Exceptions::fake();

    RecoveringThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $events = iterator_to_array((new RecoveringThrowingToolAgent)->stream('Generate'), false);

    $toolResults = array_values(array_filter($events, fn ($event): bool => $event instanceof ToolResult));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->successful)->toBeFalse()
        ->and($toolResults[0]->error)->toBe('The tool call failed: Forced to throw exception.')
        ->and(TextDelta::combine(array_filter($events, fn ($event): bool => $event instanceof TextDelta)))->toBe('The tool could not run.');

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});

test('a tool that fails while resuming an approval records the failure and then fails the run without the recovery attribute', function (): void {
    Event::fake();

    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponse('ThrowingApprovableGenerator', id: 'call_1'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingThrowingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'groq');

    expect($paused->hasPendingApprovals())->toBeTrue();

    expect(fn (): mixed => (new RememberingThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['call_1' => true]), provider: 'groq'))
        ->toThrow(Exception::class, 'Forced to throw exception.');

    Event::assertDispatchedTimes(ToolFailed::class, 1);
    Event::assertDispatched(AgentFailed::class);

    // The failure is recorded without its message, which only reaches the model for agents that opt in...
    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

    expect($row->steps)->json()->{'0'}->tool_calls->{'0'}->toMatchArray([
        'id' => 'call_1',
        'result' => 'The tool call failed.',
        'failed' => true,
    ]);

    // The paused turn is settled, so a retried approval cannot run the tool a second time...
    expect(fn (): mixed => (new RememberingThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['call_1' => true]), provider: 'groq'))
        ->toThrow(ApprovalMismatchException::class);

    Event::assertDispatchedTimes(InvokingTool::class, 1);
});

test('a tool that fails while resuming a streamed approval streams the failure before failing the run without the recovery attribute', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponse('ThrowingApprovableGenerator', id: 'call_1'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingThrowingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'groq');

    $stream = (new RememberingThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['call_1' => true]), provider: 'groq');

    $events = [];

    expect(function () use ($stream, &$events): void {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    })->toThrow(Exception::class, 'Forced to throw exception.');

    $toolResults = array_values(array_filter($events, fn ($event): bool => $event instanceof ToolResult));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->successful)->toBeFalse()
        ->and($toolResults[0]->error)->toBe('The tool call failed.');

    Http::assertSentCount(1);
});

test('a tool that fails while resuming a streamed approval streams a failed result for agents with the recovery attribute', function (): void {
    Exceptions::fake();

    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponse('ThrowingApprovableGenerator', id: 'call_1'),
            fakeGroqStreamResponse('The tool could not run.'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RecoveringThrowingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'groq');

    $events = iterator_to_array((new RecoveringThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['call_1' => true]), provider: 'groq'), false);

    $toolResults = array_values(array_filter($events, fn ($event): bool => $event instanceof ToolResult));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->successful)->toBeFalse()
        ->and($toolResults[0]->error)->toBe('The tool call failed: Forced to throw exception.')
        ->and(TextDelta::combine(array_filter($events, fn ($event): bool => $event instanceof TextDelta)))->toBe('The tool could not run.');

    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

    expect($row->steps)->json()->{'0'}->tool_calls->{'0'}->toMatchArray([
        'id' => 'call_1',
        'result' => 'The tool call failed: Forced to throw exception.',
        'failed' => true,
    ]);

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});

test('an exception from a tool event listener still fails the run for agents with the recovery attribute', function (): void {
    Exceptions::fake();

    Event::listen(InvokingTool::class, fn () => throw new RuntimeException('Listener failed.'));

    RecoveringThrowingToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Never reached.',
    ]);

    expect(fn (): mixed => (new RecoveringThrowingToolAgent)->prompt('Generate'))
        ->toThrow(RuntimeException::class, 'Listener failed.');

    Exceptions::assertNothingReported();
});

test('a tool that fails while resuming an approval skips the remaining approved tools without the recovery attribute', function (): void {
    Event::fake();

    Http::fake([
        'api.groq.com/*' => Http::sequence([
            Http::response([
                'id' => 'chatcmpl-tool-123',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ThrowingApprovableGenerator', 'arguments' => '{}']],
                            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'ThrowingApprovableGenerator', 'arguments' => '{}']],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingThrowingApprovableAgent)->forUser($user)->prompt('Generate two numbers', provider: 'groq');

    expect($paused->pendingApprovals)->toHaveCount(2);

    expect(fn (): mixed => (new RememberingThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['*' => true]), provider: 'groq'))
        ->toThrow(Exception::class, 'Forced to throw exception.');

    Event::assertDispatchedTimes(InvokingTool::class, 1);

    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

    expect($row->steps)->json()->{'0'}->tool_calls->sequence(
        fn ($toolCall) => $toolCall->toMatchArray(['id' => 'call_1', 'result' => 'The tool call failed.', 'failed' => true]),
        fn ($toolCall) => $toolCall->toMatchArray(['id' => 'call_2', 'result' => 'This tool call was not executed because an earlier tool call failed.', 'failed' => true]),
    );
});
