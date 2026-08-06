<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
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
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\DelegatingAgent;
use Tests\Fixtures\Agents\DelegatingViaCustomToolAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
use Tests\Fixtures\Agents\RateLimitedToolAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingThrowingApprovableAgent;
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

    Event::assertDispatchedTimes(AgentFailedOver::class, 1);
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

    Event::assertDispatchedTimes(AgentFailedOver::class, 1);
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
    Event::assertNotDispatched(AgentFailedOver::class);
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
    Event::assertNotDispatched(AgentFailedOver::class);
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
        && $event->response->finishReason === FinishReason::ToolCalls
        && count($event->response->toolCalls) === 1);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->stepNumber === 1
        && $event->response->finishReason === FinishReason::Stop
        && $event->response->toolCalls === []);
});

test('step completed carries the whole step response', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    (new AssistantAgent)->prompt('Hi');

    $completed = Event::dispatched(StepCompleted::class)->first()[0];

    // The response travels whole so a consumer can record the text and usage of a step, not only that it finished...
    expect($completed->response)->toBeInstanceOf(StepResponse::class)
        ->and($completed->response->text)->toBe('Hello!')
        ->and($completed->response->meta->model)->not->toBeEmpty()
        ->and($completed->response->meta->provider)->not->toBeEmpty()
        ->and($completed->response->usage)->toBeInstanceOf(Usage::class);
});

test('starting step carries the messages and options the step is sent with', function (): void {
    Event::fake();

    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    (new MultiStepToolAgent)->prompt('Generate a number');

    [$first, $second] = Event::dispatched(StartingStep::class)->map(fn (array $dispatched): StartingStep => $dispatched[0])->all();

    expect($first->messages)->toHaveCount(1)
        ->and($first->messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($first->options)->toBeInstanceOf(TextGenerationOptions::class);

    // The second step is sent the assistant turn and the tool result the first step produced...
    expect($second->messages)->toHaveCount(3)
        ->and($second->messages[2])->toBeInstanceOf(ToolResultMessage::class);
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
        && $event->response->finishReason === FinishReason::Stop);
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

test('a provider error inside the stream closes the step it opened', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->pushResponse(fakeGroqStreamErrorResponse('Upstream exploded.'));

    $response = (new AssistantAgent)->stream('Hi', provider: 'only');

    foreach ($response as $event) {
        //
    }

    // The provider reported the error in the stream rather than throwing, but the step still has to close...
    Event::assertDispatchedTimes(StartingStep::class, 1);
    Event::assertDispatchedTimes(StepFailed::class, 1);
    Event::assertNotDispatched(StepCompleted::class);

    $starting = Event::dispatched(StartingStep::class)->first()[0];
    $failed = Event::dispatched(StepFailed::class)->first()[0];

    expect($failed->invocationId)->toBe($starting->invocationId)
        ->and($failed->stepNumber)->toBe($starting->stepNumber)
        ->and($failed->exception->getMessage())->toBe('Upstream exploded.');

    // The synthesized exception cannot carry the provider's own classification, so the error event travels alongside it...
    expect($failed->error)->toBeInstanceOf(Error::class)
        ->and($failed->error->message)->toBe('Upstream exploded.')
        ->and($failed->error->type)->toBe('server_error');
});

test('a step that throws carries no stream error', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    $failed = Event::dispatched(StepFailed::class)->first()[0];

    expect($failed->error)->toBeNull()
        ->and($failed->exception)->toBeInstanceOf(RateLimitedException::class);
});

test('step events carry the agent that ran them', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    $agent = new AssistantAgent;

    $agent->prompt('Hi');

    $starting = Event::dispatched(StartingStep::class)->first()[0];
    $completed = Event::dispatched(StepCompleted::class)->first()[0];

    expect($starting->agent)->toBe($agent)
        ->and($completed->agent)->toBe($agent)
        ->and($starting->model)->not->toBeEmpty();
});

test('a failed step identifies the provider and model that failed it', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    $starting = Event::dispatched(StartingStep::class)->first()[0];
    $failed = Event::dispatched(StepFailed::class)->first()[0];

    // Failover attempts share an invocation id and both restart at step zero, so the step events must name their own provider...
    expect($failed->agent)->toBeInstanceOf(AssistantAgent::class)
        ->and($failed->provider)->toBe($starting->provider)
        ->and($failed->model)->toBe($starting->model);
});

test('a failure after failover reports the prompt the middleware produced', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    $agent = (new AssistantAgent)->withMiddleware([
        fn (AgentPrompt $prompt, Closure $next) => $next($prompt->revise('Revised by middleware.')),
    ]);

    expect(fn (): mixed => $agent->prompt('Hi', provider: ['primary', 'backup']))
        ->toThrow(RateLimitedException::class);

    $prompted = Event::dispatched(PromptingAgent::class)->first()[0];

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === $prompted->prompt->prompt
        && $event->prompt->prompt === 'Revised by middleware.');
});

test('middleware that builds its own prompt does not turn a recoverable failover into a failure', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(fakeGroqResponse('Hello from the backup.'));

    // A hand built prompt cannot carry the attempt bookkeeping forward, so terminality must be read from ours...
    $agent = (new AssistantAgent)->withMiddleware([
        fn (AgentPrompt $prompt, Closure $next) => $next(new AgentPrompt(
            $prompt->agent,
            'Rebuilt by middleware.',
            $prompt->attachments,
            $prompt->provider,
            $prompt->model,
            invocationId: $prompt->invocationId,
        )),
    ]);

    $response = $agent->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    Event::assertDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(AgentFailed::class);
});

test('a streamed failure after the stream started reports the run as failed', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()->pushResponse(fakeGroqStreamToolCallResponse('RateLimitedNumberGenerator'));

    $response = (new RateLimitedToolAgent)->stream('Generate', provider: ['primary', 'backup']);

    $seen = 0;

    // The stream had already reached the consumer, so the run can no longer be replayed against the backup...
    expect(function () use ($response, &$seen): void {
        foreach ($response as $event) {
            $seen++;
        }
    })->toThrow(RateLimitedException::class, 'Rate limited while running the tool.');

    expect($seen)->toBeGreaterThan(0);

    Event::assertDispatchedTimes(AgentFailed::class, 1);
    Event::assertNotDispatched(AgentFailedOver::class);
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

test('a tool that fails while resuming an approval reports the failure once and continues the run', function (): void {
    Event::fake();

    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ThrowingApprovableGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The tool could not run.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingThrowingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    $resumed = (new RememberingThrowingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    // The resume path turns the failure into a tool result, so the run survives but still reports the failure exactly once...
    Event::assertDispatchedTimes(ToolFailed::class, 1);
    Event::assertNotDispatched(ToolInvoked::class);
    Event::assertNotDispatched(AgentFailed::class);

    expect($resumed->text)->toBe('The tool could not run.');

    $failed = Event::dispatched(ToolFailed::class)->first()[0];
    $invoking = Event::dispatched(InvokingTool::class)->first()[0];

    expect($failed->exception->getMessage())->toBe('Forced to throw exception.')
        ->and($failed->toolInvocationId)->toBe($invoking->toolInvocationId);
});

test('a streamed failure after failover reports the prompt the middleware produced', function (): void {
    Event::fake();

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(status: 429);

    $agent = (new AssistantAgent)->withMiddleware([
        fn (AgentPrompt $prompt, Closure $next) => $next($prompt->revise('Revised by middleware.')),
    ]);

    expect(function () use ($agent): void {
        foreach ($agent->stream('Hi', provider: ['primary', 'backup']) as $event) {
            //
        }
    })->toThrow(RateLimitedException::class);

    $streaming = Event::dispatched(StreamingAgent::class)->first()[0];

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === $streaming->prompt->prompt
        && $event->prompt->prompt === 'Revised by middleware.');
});

test('a single provider streamed failure reports the prompt the middleware produced', function (): void {
    Event::fake();

    config(['ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    $agent = (new AssistantAgent)->withMiddleware([
        fn (AgentPrompt $prompt, Closure $next) => $next($prompt->revise('Revised by middleware.')),
    ]);

    expect(function () use ($agent): void {
        foreach ($agent->stream('Hi', provider: 'primary') as $event) {
            //
        }
    })->toThrow(RateLimitedException::class);

    $streaming = Event::dispatched(StreamingAgent::class)->first()[0];

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === $streaming->prompt->prompt
        && $event->prompt->prompt === 'Revised by middleware.');
});

test('a single provider synchronous failure reports the prompt the middleware produced', function (): void {
    Event::fake();

    config(['ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    $agent = (new AssistantAgent)->withMiddleware([
        fn (AgentPrompt $prompt, Closure $next) => $next($prompt->revise('Revised by middleware.')),
    ]);

    expect(fn (): mixed => $agent->prompt('Hi', provider: 'primary'))
        ->toThrow(RateLimitedException::class);

    $prompted = Event::dispatched(PromptingAgent::class)->first()[0];

    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === $prompted->prompt->prompt
        && $event->prompt->prompt === 'Revised by middleware.');
});

test('every step event names the provider and model the step ran against', function (): void {
    Event::fake();

    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    (new MultiStepToolAgent)->prompt('Generate a number');

    $starting = Event::dispatched(StartingStep::class)->first()[0];
    $completed = Event::dispatched(StepCompleted::class)->first()[0];

    // A consumer builds a span from whichever event it sees, so the identity is read the same way on all of them...
    expect($completed->provider)->toBe($starting->provider)
        ->and($completed->model)->toBe($starting->model)
        ->and($completed->isFinalStep)->toBe($starting->isFinalStep)
        ->and($completed->agent)->toBe($starting->agent);
});

test('step completed carries the wall time spent in the provider call', function (): void {
    Event::fake();

    AssistantAgent::fake(['Hello!']);

    (new AssistantAgent)->prompt('Hi');

    $completed = Event::dispatched(StepCompleted::class)->first()[0];

    expect($completed->time)->toBeFloat()->toBeGreaterThan(0.0);
});

test('step failed carries the wall time spent before the failure', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    $failed = Event::dispatched(StepFailed::class)->first()[0];

    expect($failed->time)->toBeFloat()->toBeGreaterThan(0.0);
});

test('tool events carry the wall time spent in the tool', function (): void {
    Event::fake();

    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    (new MultiStepToolAgent)->prompt('Generate a number');

    $invoked = Event::dispatched(ToolInvoked::class)->first()[0];

    expect($invoked->time)->toBeFloat()->toBeGreaterThan(0.0);
});

test('a failed over run names the invocation it belongs to', function (): void {
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

    Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);
});
