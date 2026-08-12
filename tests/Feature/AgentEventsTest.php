<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\OrchestratorAgent;
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

test('a step that throws carries no stream error', function (): void {
    Event::fake();

    config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

    Http::preventStrayRequests();

    Http::fakeSequence()->push(status: 429);

    expect(fn (): mixed => (new AssistantAgent)->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    $failed = Event::dispatched(StepFailed::class)->first()[0];

    expect($failed->exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($failed->exception)->not->toBeInstanceOf(StreamErrorException::class);
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

    $invoking = Event::dispatched(InvokingTool::class)->first()[0];
    $failed = Event::dispatched(ToolFailed::class)->first()[0];

    expect($failed->toolInvocationId)->toBe($invoking->toolInvocationId)
        ->and($failed->invocationId)->toBe($invoking->invocationId);
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

    expect($resumed->text)->toBe('The tool could not run.');

    $failed = Event::dispatched(ToolFailed::class)->first()[0];
    $invoking = Event::dispatched(InvokingTool::class)->first()[0];

    expect($failed->exception->getMessage())->toBe('Forced to throw exception.')
        ->and($failed->toolInvocationId)->toBe($invoking->toolInvocationId);
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
