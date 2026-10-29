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

    expect(fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'))
        ->toThrow(Exception::class, 'Forced to throw exception.');
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
        ->and($response->toolResults[0]->failed)->toBeTrue()
        ->and($response->toolResults[0]->result)->toBe('The tool call failed: Forced to throw exception.');

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
    $steps = json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true);

    expect($steps[0]['tool_calls'][0])->toMatchArray([
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

test('a tool that fails while resuming an approval returns a failed result to the model for agents with the recovery attribute', function (): void {
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

    $steps = json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->oldest('id')->value('steps'), true);

    expect($steps[0]['tool_calls'][0])->toMatchArray([
        'id' => 'call_1',
        'result' => 'The tool call failed: Forced to throw exception.',
        'failed' => true,
    ]);

    Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
});
