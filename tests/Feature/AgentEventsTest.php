<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\DelegatingViaCustomToolAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\ResearchAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\FakeConversationStore;
use Tests\Fixtures\Tools\FixedNumberGenerator;

test('a synchronous prompt threads one invocation id through the prompt and its events', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    $response = (new AssistantAgent)->prompt('Hi');

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->invocationId === $response->invocationId
        && $event->prompt->invocationId === $response->invocationId);

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->invocationId === $response->invocationId);
});

test('synchronous middleware receives the invocation id the run reports', function (): void {
    AssistantAgent::fake(['Hello!']);

    $seen = null;

    $response = (new AssistantAgent)->withMiddleware([
        function (AgentPrompt $prompt, Closure $next) use (&$seen) {
            $seen = $prompt->invocationId;

            return $next($prompt);
        },
    ])->prompt('Hi');

    expect($seen)->not->toBeNull()->toBe($response->invocationId);
});

test('every failover attempt shares the run invocation id', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);

    $invocationIds = Event::dispatched(PromptingAgent::class)
        ->map(fn (array $dispatched): string => $dispatched[0]->invocationId)
        ->unique();

    expect($invocationIds)->toHaveCount(1);
});

test('a recoverable failover does not report the run as failed', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    // The recoverable attempt is reported through the per-step event, not the terminal one...
    Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->invocationId === $response->invocationId);
    Event::assertDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentPrompted::class);
    Event::assertNotDispatched(AgentFailed::class);
});

test('a recoverable failover does not report the streamed run as failed', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqStreamResponse('Hello from the backup.'));

    $response = (new AssistantAgent)->stream('Hi', provider: ['primary', 'backup']);

    foreach ($response as $event) {
        //
    }

    Event::assertDispatched(StepFailed::class);
    Event::assertDispatched(AgentFailedOver::class);
    Event::assertDispatched(AgentStreamed::class);
    Event::assertNotDispatched(AgentFailed::class);
});

test('a run that exhausts every provider reports the failure once', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']))
        ->toThrow(RateLimitedException::class);

    Event::assertDispatchedTimes(AgentFailedOver::class, 2);
    Event::assertDispatchedTimes(AgentFailed::class, 1);

    $failed = Event::dispatched(AgentFailed::class)->first()[0];
    $failedOver = Event::dispatched(AgentFailedOver::class)->first()[0];

    expect($failed->invocationId)->toBe($failedOver->invocationId)
        ->and($failed->prompt->prompt)->toBe('Hi');
});

test('a streamed run that exhausts every provider reports the failure once', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    $response = (new AssistantAgent)->stream('Hi', provider: ['primary', 'backup']);

    expect(function () use ($response): void {
        foreach ($response as $event) {
            //
        }
    })->toThrow(RateLimitedException::class);

    Event::assertDispatchedTimes(AgentFailedOver::class, 2);
    Event::assertDispatchedTimes(AgentFailed::class, 1);

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId);
});

test('a single provider run with no failover still reports a failoverable failure', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    Event::assertDispatchedTimes(AgentFailed::class, 1);
});

test('a single provider stream with no failover still reports a failoverable failure', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    $response = (new AssistantAgent)->stream('Hi', provider: 'only');

    expect(function () use ($response): void {
        foreach ($response as $event) {
            //
        }
    })->toThrow(RateLimitedException::class);

    Event::assertDispatchedTimes(AgentFailed::class, 1);
});

test('step events are dispatched for each step of a tool calling run', function (): void {
    Event::fake();

    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    $response = (new MultiStepToolAgent)->prompt('Generate a number');

    Event::assertDispatchedTimes(StartingStep::class, 2);
    Event::assertDispatchedTimes(StepCompleted::class, 2);

    Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->isFinalStep === false
        && $event->model !== '');

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->finishReason === FinishReason::ToolCalls
        && count($event->toolCalls) === 1);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->stepNumber === 1
        && $event->finishReason === FinishReason::Stop
        && $event->toolCalls === []);
});

test('step completed carries the meta of the step it reports', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    (new AssistantAgent)->prompt('Hi');

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->meta->model !== ''
        && $event->meta->provider !== '');
});

test('conversation title generation does not report steps against the run that triggered it', function (): void {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    Event::fake();

    RememberingAssistantAgent::fake(['Fake response', 'A Nice Title']);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)->forUser($user)->prompt('Test prompt');

    // Title generation runs its own loop on the same provider, and must not be attributed to this run...
    Event::assertDispatchedTimes(StartingStep::class, 1);
    Event::assertDispatchedTimes(StepCompleted::class, 1);

    Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0);
});

test('step events are dispatched on the streaming path', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    $response = (new AssistantAgent)->stream('Hi');

    foreach ($response as $event) {
        //
    }

    Event::assertDispatchedTimes(StartingStep::class, 1);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->finishReason === FinishReason::Stop);
});

test('a failing gateway dispatches step failed and agent failed instead of agent prompted', function (): void {
    Event::fake();

    AssistantAgent::fake([fn () => throw new RuntimeException('Provider exploded.')]);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi'))
        ->toThrow(RuntimeException::class, 'Provider exploded.');

    Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->stepNumber === 0
        && $event->exception->getMessage() === 'Provider exploded.');

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === 'Hi'
        && $event->exception->getMessage() === 'Provider exploded.');

    Event::assertNotDispatched(AgentPrompted::class);

    $stepFailed = Event::dispatched(StepFailed::class)->first()[0];
    $agentFailed = Event::dispatched(AgentFailed::class)->first()[0];

    expect($stepFailed->invocationId)->toBe($agentFailed->invocationId);
});

test('a failing stream dispatches agent failed instead of agent streamed', function (): void {
    Event::fake();

    AssistantAgent::fake([fn () => throw new RuntimeException('Stream exploded.')]);

    $response = (new AssistantAgent)->stream('Hi');

    expect(function () use ($response): void {
        foreach ($response as $event) {
            //
        }
    })->toThrow(RuntimeException::class, 'Stream exploded.');

    Event::assertDispatched(StepFailed::class);

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception->getMessage() === 'Stream exploded.');

    Event::assertNotDispatched(AgentStreamed::class);
});

test('a throwing tool dispatches tool failed and still propagates the exception', function (): void {
    Event::fake();

    ToolUsingAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        ['number' => 5],
    ]);

    expect(fn (): mixed => (new ToolUsingAgent(fixed: true, toolThrowsException: true))->prompt('Generate'))
        ->toThrow(Exception::class, 'Forced to throw exception.');

    Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->tool instanceof FixedNumberGenerator
        && $event->exception->getMessage() === 'Forced to throw exception.');

    Event::assertNotDispatched(ToolInvoked::class);

    Event::assertDispatched(AgentFailed::class);

    $invoking = Event::dispatched(InvokingTool::class)->first()[0];
    $failed = Event::dispatched(ToolFailed::class)->first()[0];

    expect($failed->toolInvocationId)->toBe($invoking->toolInvocationId)
        ->and($failed->invocationId)->toBe($invoking->invocationId);
});

test('a nested sub agent sharing the parent provider instance does not overwrite the parent tool invocation id', function (): void {
    Event::fake();

    // Unfaked agents share one memoized provider, and therefore one generation loop, which is the case the tool invocation id has to survive...
    config([
        'ai.default' => 'shared',
        'ai.providers.shared' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->pushResponse(fakeGroqToolCallResponse('middle_manager', ['task' => 'Deep-dive on Laravel caching'], 'call_001'))
        ->pushResponse(fakeGroqToolCallResponse('research_agent', ['task' => 'Research Laravel caching internals'], 'call_002'))
        ->pushResponse(fakeGroqResponse('Deep research result'))
        ->pushResponse(fakeGroqResponse('Research delegated.'))
        ->pushResponse(fakeGroqResponse('Delegated to middle manager.'));

    (new OrchestratorAgent)->prompt('Do a deep dive on Laravel caching');

    $invoking = Event::dispatched(InvokingTool::class)->map(fn (array $dispatched) => $dispatched[0]);
    $invoked = Event::dispatched(ToolInvoked::class)->map(fn (array $dispatched) => $dispatched[0]);

    expect($invoking)->toHaveCount(2)->and($invoked)->toHaveCount(2);

    $delegatedTo = fn (string $agent): Closure => fn ($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof $agent;

    foreach ([MiddleManagerAgent::class, ResearchAgent::class] as $agent) {
        $start = $invoking->first($delegatedTo($agent));
        $end = $invoked->first($delegatedTo($agent));

        expect($end)->not->toBeNull()
            ->and($end->toolInvocationId)->toBe($start->toolInvocationId);
    }

    expect($invoking->map(fn ($event): string => $event->toolInvocationId)->unique())->toHaveCount(2);
});

test('a sub agent prompt is linked to the parent invocation and tool invocation', function (): void {
    Event::fake();

    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research Laravel']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent)->prompt('Delegate research about Laravel');

    $invoking = Event::dispatched(InvokingTool::class)->first()[0];

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
        && $event->prompt->parentInvocationId === $response->invocationId
        && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof DelegatingAgent
        && $event->prompt->parentInvocationId === null
        && $event->prompt->parentToolInvocationId === null);
});

test('an agent prompted from a hand written tool is linked to the parent invocation', function (): void {
    Event::fake();

    DelegatingViaCustomToolAgent::fake([
        new ToolCall('call_1', 'AgentCallingTool', []),
        'Done.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingViaCustomToolAgent)->prompt('Go');

    $invoking = Event::dispatched(InvokingTool::class)->first()[0];

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
        && $event->prompt->parentInvocationId === $response->invocationId
        && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);
});

test('the parent invocation is restored once a tool call finishes', function (): void {
    Event::fake();

    DelegatingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'Research Laravel']),
        'Delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    (new DelegatingAgent)->prompt('Delegate');

    ResearchAgent::fake(['Standalone result']);

    (new ResearchAgent)->prompt('Standalone');

    $standalone = Event::dispatched(PromptingAgent::class)
        ->map(fn (array $dispatched) => $dispatched[0]->prompt)
        ->last(fn ($prompt): bool => $prompt->agent instanceof ResearchAgent);

    expect($standalone->parentInvocationId)->toBeNull()
        ->and($standalone->parentToolInvocationId)->toBeNull();
});

test('the parent invocation never travels outside the current process', function (): void {
    $insideDehydratedContext = null;

    ParentInvocation::within('inv_1', 'tool_1', function () use (&$insideDehydratedContext): void {
        $insideDehydratedContext = Context::dehydrate();

        expect(ParentInvocation::current())->toBe(['inv_1', 'tool_1']);
    });

    // Queued jobs serialize the log context, so the delegating pair must never be stored there...
    expect($insideDehydratedContext)->toBeNull()
        ->and(ParentInvocation::current())->toBe([null, null]);
});
