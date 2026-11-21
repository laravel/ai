<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingFailoverAgent;
use Tests\Fixtures\Agents\RememberingMiddleManagerAgent;
use Tests\Fixtures\Agents\RememberingOrchestratingAgent;
use Tests\Fixtures\Agents\RememberingSubAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ResearchAgent;
use Tests\Fixtures\ConversationStores\InMemoryConversationStore;
use Tests\Fixtures\Tools\AgentCallingTool;
use Tests\Fixtures\Tools\FixedNumberGenerator;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-key',
    ]);
});

/**
 * Get the IDs of the conversations stored for the given participant, in order.
 *
 * @return list<string>
 */
function participantConversations(int $participantId): array
{
    return DB::table('agent_conversations')->where('participant_id', $participantId)->orderBy('id')->pluck('id')->all();
}

/**
 * Get the number of conversations stored without a participant.
 */
function ownerlessConversationCount(): int
{
    return DB::table('agent_conversations')->whereNull('participant_id')->count();
}

/**
 * Fake the provider responses of a parent that delegates two tasks on each of the given runs.
 */
function fakeDelegatingRuns(string $mode, int $runs): void
{
    $sequence = Http::sequence();

    foreach (range(1, $runs) as $run) {
        foreach (['one', 'two'] as $task) {
            $sequence->pushResponse($mode === 'prompt'
                ? fakeAnthropicToolUseResponse('RememberingSubAgent', ['task' => "run {$run} {$task}"], "toolu_{$run}_{$task}")
                : fakeAnthropicStreamToolUseResponse('RememberingSubAgent', ['task' => "run {$run} {$task}"], "toolu_{$run}_{$task}"));
        }

        $sequence->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('Done.') : fakeAnthropicStreamResponse('Done.'));
    }

    Http::fake(['api.anthropic.com/*' => $sequence]);
}

/**
 * Get the IDs of the conversations a user message with the given content was stored in, in order.
 *
 * @return list<string>
 */
function conversationsContaining(string $message): array
{
    return DB::table('agent_conversation_messages')
        ->where('role', 'user')
        ->where('content', $message)
        ->orderBy('id')
        ->pluck('conversation_id')
        ->all();
}

test('a sub-agent given a user is stored under that user and linked', function (string $mode): void {
    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser((object) ['id' => 2])]))->forUser((object) ['id' => 1]),
        $mode,
        'Delegate both tasks',
    );

    $conversations = participantConversations(2);

    expect($conversations)->toBe([conversationContaining('one'), conversationContaining('two')])
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe($conversations)
        ->and(participantConversations(1))->toBe([$response->conversationId])
        ->and(ownerlessConversationCount())->toBe(0);
})->with(['prompt', 'stream']);

test('nested delegations are each stored and linked from the agent that delegated them', function (string $mode): void {
    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingMiddleManagerAgent', ['task' => 'Deep-dive on caching']), 'Done.']);
    RememberingMiddleManagerAgent::fake([new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'Research cache drivers']), 'Delegated.']);
    RememberingSubAgent::fake(['Research result']);

    $middleManager = (new RememberingMiddleManagerAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user);

    $response = promptOrStream((new RememberingOrchestratingAgent([$middleManager]))->forUser($user), $mode, 'Delegate');

    $middle = conversationContaining('Deep-dive on caching');
    $leaf = conversationContaining('Research cache drivers');

    expect(participantConversations(1))->toHaveCount(3)
        ->and(ownerlessConversationCount())->toBe(0)
        ->and(storedToolCalls($response->conversationId)[0]['sub_conversation_id'])->toBe($middle)
        ->and(storedToolCalls($middle)[0]['sub_conversation_id'])->toBe($leaf)
        ->and(storedMessageContents($leaf))->toBe(['Research cache drivers', 'Research result']);
})->with(['prompt', 'stream']);

test('an agent prompted from a custom tool is not stored or linked', function (): void {
    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'AgentCallingTool', []), 'Done.']);
    ResearchAgent::fake(['Research result']);

    $response = (new RememberingOrchestratingAgent([new AgentCallingTool]))->forUser((object) ['id' => 1])->prompt('Research');

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe([$response->conversationId])
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['result' => 'Research result'])->not->toHaveKey('sub_conversation_id');
});

test('a delegation that cannot be stored fails its tool call instead of the parent', function (string $mode, string $failingMethod): void {
    app()->instance(ConversationStore::class, new class($failingMethod) extends DatabaseConversationStore
    {
        public function __construct(public string $failingMethod)
        {
            parent::__construct();
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            throw_if($this->failingMethod === __FUNCTION__ && $participantId === 2, new RuntimeException('The store is down.'));

            return parent::storeConversation($participantType, $participantId, $title, $id);
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
        {
            throw_if($this->failingMethod === __FUNCTION__ && $participantId === 2, new RuntimeException('The store is down.'));

            return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
        }
    });

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser((object) ['id' => 2])]))->forUser((object) ['id' => 1]),
        $mode,
        'Delegate',
    );

    expect($response->text)->toBe('Done.')
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['failed' => true])
        ->and(storedToolCalls($response->conversationId)[0]['result'])->toContain('The store is down.');
})->with(['prompt', 'stream'])->with(['storeConversation', 'storeAssistantMessage']);

test('a parent that fails over links the delegation of the attempt that succeeded', function (): void {
    config([
        'ai.conversations.generate_title' => false,
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    $user = (object) ['id' => 1];

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->pushResponse(fakeGroqToolCallResponse('RememberingSubAgent', ['task' => 'First attempt'], 'call_1'))
        ->push(status: 429)
        ->pushResponse(fakeGroqToolCallResponse('RememberingSubAgent', ['task' => 'Second attempt'], 'call_2'))
        ->pushResponse(fakeGroqResponse('Done.'));

    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))
        ->forUser($user)
        ->prompt('Delegate', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Done.')
        ->and(storedToolCalls($response->conversationId))->toHaveCount(1)
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray([
            'result' => 'Second result',
            'sub_conversation_id' => conversationContaining('Second attempt'),
        ])
        ->and(conversationContaining('Second attempt'))->not->toBe(conversationContaining('First attempt'))
        ->and(ownerlessConversationCount())->toBe(0);
});

test('a sub-agent that fails over is stored and linked', function (string $mode): void {
    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    $user = (object) ['id' => 1];

    Event::fake([AgentFailedOver::class]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse($mode === 'prompt' ? fakeGroqResponse('First result') : fakeGroqStreamResponse('First result'))
        ->push(status: 429)
        ->pushResponse($mode === 'prompt' ? fakeGroqResponse('Second result') : fakeGroqStreamResponse('Second result'));

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingFailoverAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingFailoverAgent', ['task' => 'two']),
        'Done.',
    ]);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingFailoverAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate both tasks',
    );

    Event::assertDispatchedTimes(AgentFailedOver::class, 2);

    expect(storedToolCalls($response->conversationId))->sequence(
        fn ($call) => $call->toMatchArray(['result' => 'First result', 'sub_conversation_id' => conversationContaining('one')]),
        fn ($call) => $call->toMatchArray(['result' => 'Second result', 'sub_conversation_id' => conversationContaining('two')]),
    )
        ->and(conversationContaining('one'))->not->toBe(conversationContaining('two'))
        ->and(participantConversations(1))->toHaveCount(3)
        ->and(ownerlessConversationCount())->toBe(0);
})->with(['prompt', 'stream']);

test('queued parents store and link their delegations', function (): void {
    config(['ai.conversations.generate_title' => false]);

    $user = (object) ['id' => 1];

    fakeDelegatingRuns('prompt', runs: 2);

    RememberingSubAgent::fake(['Result 1', 'Result 2', 'Result 3', 'Result 4']);

    foreach ([1, 2] as $run) {
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->queue('Delegate both tasks');
    }

    $parents = conversationsContaining('Delegate both tasks');

    expect($parents)->toHaveCount(2)
        ->and(participantConversations(1))->toHaveCount(6)
        ->and(ownerlessConversationCount())->toBe(0)
        ->and(array_column(storedToolCalls($parents[0]), 'sub_conversation_id'))->toBe([conversationContaining('run 1 one'), conversationContaining('run 1 two')])
        ->and(array_column(storedToolCalls($parents[1]), 'sub_conversation_id'))->toBe([conversationContaining('run 2 one'), conversationContaining('run 2 two')]);
});

test('broadcast parents store and link their delegations', function (string $method): void {
    config(['ai.conversations.generate_title' => false]);

    $user = (object) ['id' => 1];

    Event::fake([AnonymousEvent::class]);

    fakeDelegatingRuns('stream', runs: 2);

    RememberingSubAgent::fake(['Result 1', 'Result 2', 'Result 3', 'Result 4']);

    foreach ([1, 2] as $run) {
        $orchestrator = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user);

        $method === 'broadcastNow'
            ? iterator_to_array($orchestrator->broadcastNow('Delegate both tasks', new Channel("run-{$run}")))
            : $orchestrator->broadcastOnQueue('Delegate both tasks', new Channel("run-{$run}"));
    }

    $parents = conversationsContaining('Delegate both tasks');
    $links = [...array_column(storedToolCalls($parents[0]), 'sub_conversation_id'), ...array_column(storedToolCalls($parents[1]), 'sub_conversation_id')];

    expect($links)->toBe([
        conversationContaining('run 1 one'),
        conversationContaining('run 1 two'),
        conversationContaining('run 2 one'),
        conversationContaining('run 2 two'),
    ])
        ->and(participantConversations(1))->toHaveCount(6)
        ->and(ownerlessConversationCount())->toBe(0);

    $broadcasts = Event::dispatched(AnonymousEvent::class)->map(fn (array $event) => json_encode($event[0]->broadcastWith()))->join("\n");

    expect($broadcasts)->toContain('Result 1')->not->toContain('sub_conversation');

    foreach ($links as $link) {
        expect($broadcasts)->not->toContain($link);
    }
})->with(['broadcastNow', 'broadcastOnQueue']);

test('delegations are stored by a custom conversation store', function (string $mode): void {
    app()->instance(ConversationStore::class, $store = new InMemoryConversationStore);

    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);
    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = promptOrStream((new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user), $mode, 'Delegate both tasks');

    $conversationOf = fn (string $content) => collect($store->messages)->firstWhere('content', $content)['conversation_id'];

    expect($store->conversations)->toHaveCount(3)
        ->and(array_column($store->conversations, 'participant_id'))->toBe([1, 1, 1])
        ->and($conversationOf('Delegate both tasks'))->toBe($response->conversationId)
        ->and($conversationOf('one'))->not->toBe($conversationOf('two'))
        ->and($conversationOf('First result'))->toBe($conversationOf('one'))
        ->and($conversationOf('Second result'))->toBe($conversationOf('two'));
})->with(['prompt', 'stream']);

test('a delegation run by a tool wrapping an agent tool is linked', function (string $mode): void {
    $user = (object) ['id' => 1];

    $tool = new class(new AgentTool((new RememberingSubAgent)->forUser($user))) implements Tool
    {
        public function __construct(public AgentTool $inner) {}

        public function name(): string
        {
            return 'RememberingSubAgent';
        }

        public function description(): string
        {
            return 'Delegate a task.';
        }

        public function schema(JsonSchema $schema): array
        {
            return $this->inner->schema($schema);
        }

        public function handle(Request $request): string
        {
            return $this->inner->handle($request);
        }
    };

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $response = promptOrStream((new RememberingOrchestratingAgent([$tool]))->forUser($user), $mode, 'Delegate');

    expect(storedToolCalls($response->conversationId)[0]['sub_conversation_id'])->toBe(conversationContaining('one'))
        ->and(participantConversations(1))->toBe([$response->conversationId, conversationContaining('one')]);
})->with(['prompt', 'stream']);

test('an agent tool given the invocation but no call ID runs without a link', function (): void {
    $user = (object) ['id' => 1];

    $tool = new class(new AgentTool((new RememberingSubAgent)->forUser($user))) implements Tool
    {
        public function __construct(public AgentTool $inner) {}

        public function name(): string
        {
            return 'RememberingSubAgent';
        }

        public function description(): string
        {
            return 'Delegate a task.';
        }

        public function schema(JsonSchema $schema): array
        {
            return $this->inner->schema($schema);
        }

        public function handle(Request $request): string
        {
            return $this->inner->handle(new Request(['task' => $request['task']], null, $request->toolInvocationId()));
        }
    };

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $response = (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate');

    expect(conversationContaining('one'))->not->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['result' => 'First result'])
        ->not->toHaveKey('sub_conversation_id');
});

test('a call an agent tool answers itself is not linked to a delegation of an earlier run', function (): void {
    $user = (object) ['id' => 1];
    $listenerFails = true;

    $tool = new class((new RememberingSubAgent)->forUser($user)) extends AgentTool
    {
        public function handle(Request $request): string
        {
            return $request['task'] === 'cached' ? 'Cached result' : parent::handle($request);
        }
    };

    // A tool call ID is only unique within its turn, so a later run may be given the same one...
    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'cached']),
        'Done.',
    ]);
    RememberingSubAgent::fake(['First result']);

    Event::listen(ToolInvoked::class, function () use (&$listenerFails): void {
        throw_if($listenerFails, new RuntimeException('The listener failed.'));
    });

    expect(fn () => (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate'))
        ->toThrow(RuntimeException::class, 'The listener failed.');

    $listenerFails = false;

    $response = (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate again');

    expect(conversationContaining('one'))->not->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['id' => 'call_1', 'result' => 'Cached result'])
        ->not->toHaveKey('sub_conversation_id');
});

test('a delegation that stores nothing is not linked to an earlier delegation of the same tool', function (): void {
    $user = (object) ['id' => 1];
    $tool = new AgentTool((new RememberingToolUsingAgent)->forUser($user));
    $listenerFails = true;

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    // A tool call ID is only unique within its turn, so a later run may be given the same one...
    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingToolUsingAgent', ['task' => 'one']),
        new ToolCall('call_1', 'RememberingToolUsingAgent', ['task' => 'two']),
        'Done.',
    ]);

    Event::listen(ToolInvoked::class, function () use (&$listenerFails): void {
        throw_if($listenerFails, new RuntimeException('The listener failed.'));
    });

    expect(fn () => (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate'))
        ->toThrow(RuntimeException::class, 'The listener failed.');

    $listenerFails = false;

    $response = (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate again');

    expect(conversationContaining('one'))->not->toBeNull()
        ->and(conversationContaining('two'))->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray(['id' => 'call_1', 'failed' => true])
        ->not->toHaveKey('sub_conversation_id');
});

test('a later call reusing a delegation\'s call ID in the same run is not linked to it', function (string $mode): void {
    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Done.',
    ]);
    RememberingSubAgent::fake(['First result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user), new FixedNumberGenerator]))->forUser($user),
        $mode,
        'Delegate',
    );

    $calls = storedToolCalls($response->conversationId);

    expect($calls[0]['sub_conversation_id'])->toBe(conversationContaining('one'))
        ->and($calls[1])->toMatchArray(['id' => 'call_1', 'result' => '72019'])
        ->not->toHaveKey('sub_conversation_id');
})->with(['prompt', 'stream']);

test('no delegation state outlives the parent run', function (Closure $run): void {
    // Earlier tests may leave runs to the cycle collector, which forgets them as it collects them...
    gc_collect_cycles();

    $registry = new ReflectionProperty(AgentTool::class, 'delegations');
    $subAgent = (new RememberingSubAgent)->forUser((object) ['id' => 1]);
    $running = null;

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake([function () use ($registry, &$running): string {
        $running = $registry->getValue();

        return 'First result';
    }]);

    $run((new RememberingOrchestratingAgent([$subAgent]))->forUser((object) ['id' => 1]));

    expect($running)->toHaveCount(1)
        ->and(array_values($running)[0])->sequence(
            fn ($agent) => $agent->toBe($subAgent),
            fn ($conversation) => $conversation->toBeNull(),
            fn ($claimedBy) => $claimedBy->toBeString(),
        )
        ->and($registry->getValue())->toBe([])
        ->and(ParentInvocation::context())->toBeNull();
})->with([
    'a prompted run' => fn (Agent $agent) => $agent->prompt('Delegate'),
    'a streamed run' => fn (Agent $agent) => iterator_to_array($agent->stream('Delegate')),
    'a stream abandoned during the delegation' => function (Agent $agent): void {
        foreach ($agent->stream('Delegate') as $event) {
            if ($event instanceof ToolResultEvent && $event->preliminary) {
                break;
            }
        }
    },
    'a stream whose consumer throws during the delegation' => function (Agent $agent): void {
        try {
            foreach ($agent->stream('Delegate') as $event) {
                if ($event instanceof ToolResultEvent && $event->preliminary) {
                    throw new RuntimeException('The consumer failed.');
                }
            }
        } catch (RuntimeException) {
            //
        }
    },
    'a run whose tool listener throws after the delegation' => function (Agent $agent): void {
        Event::listen(ToolInvoked::class, fn () => throw new RuntimeException('The listener failed.'));

        expect(fn () => $agent->prompt('Delegate'))->toThrow(RuntimeException::class, 'The listener failed.');
    },
]);

test('no delegation state outlives a parent run whose sub-agent fails', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()->pushStatus(500)->pushStatus(500)->pushStatus(500)]);

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingToolUsingAgent', ['task' => 'one']), 'Done.']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingToolUsingAgent)->forUser($user)]))->forUser($user),
        $mode,
        'Delegate',
    );

    expect(storedToolCalls($response->conversationId)[0])->toMatchArray(['failed' => true])
        ->and((new ReflectionProperty(AgentTool::class, 'delegations'))->getValue())->toBe([])
        ->and(ParentInvocation::context())->toBeNull();
})->with(['prompt', 'stream']);

test('an abandoned streamed delegation freed during another run does not touch that run\'s links', function (): void {
    $user = (object) ['id' => 1];

    // A tool call ID is only unique within its turn, so a later run may be given the same one...
    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'abandoned']),
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);
    RememberingSubAgent::fake(['Abandoned result', 'Second result']);

    // The consumer stops reading while the delegation runs, and keeps the stream around...
    $stream = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->stream('Delegate');
    $events = $stream->getIterator();

    foreach ($events as $event) {
        if ($event instanceof ToolResultEvent && $event->preliminary) {
            break;
        }
    }

    // ...until it is freed, here by hand and in production by the cycle collector, after another run's delegation was linked...
    Event::listen(ToolInvoked::class, function () use (&$stream, &$events): void {
        $stream = $events = null;

        gc_collect_cycles();
    });

    $response = (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->prompt('Delegate again');

    expect($stream)->toBeNull()
        ->and(conversationContaining('two'))->not->toBeNull()
        ->and(storedToolCalls($response->conversationId)[0]['sub_conversation_id'] ?? null)->toBe(conversationContaining('two'));
});

test('the sub-agent prompted by the stream consumer while its delegation is suspended keeps its own conversation', function (): void {
    $user = (object) ['id' => 1];
    $subAgent = (new RememberingSubAgent)->forUser($user);
    $consumerConversation = null;

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result', 'Consumer answer']);

    $response = (new RememberingOrchestratingAgent([$subAgent]))->forUser($user)->stream('Delegate');

    foreach ($response as $event) {
        if ($consumerConversation === null && $event instanceof ToolResultEvent && $event->preliminary) {
            $consumerConversation = $subAgent->prompt('Consumer task')->conversationId;
        }
    }

    $link = storedToolCalls($response->conversationId)[0]['sub_conversation_id'];

    expect($consumerConversation)->not->toBeNull()
        ->and($subAgent->currentConversation())->toBe($consumerConversation)
        ->and($link)->toBe(conversationContaining('one'))
        ->and($link)->not->toBe($consumerConversation)
        ->and(storedMessageContents($consumerConversation))->toBe(['Consumer task', 'Consumer answer']);
});

test('a remembering agent prompted while a sub-agent that is not remembered runs keeps its own conversation', function (): void {
    $user = (object) ['id' => 1];
    $helper = (new RememberingAssistantAgent)->forUser($user);

    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use ($helper): void {
        // Exactly the sub-agent's class: the helper extends AssistantAgent...
        if ($event->prompt->agent::class === AssistantAgent::class) {
            $helper->prompt('Side task');
        }
    });

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'AssistantAgent', ['task' => 'one']),
        new ToolCall('call_2', 'AssistantAgent', ['task' => 'two']),
        'Done.',
    ]);
    AssistantAgent::fake(['First result', 'Second result']);
    RememberingAssistantAgent::fake(['First side answer', 'Second side answer']);

    $response = (new RememberingOrchestratingAgent([new AssistantAgent]))->forUser($user)->prompt('Delegate both tasks');

    expect($helper->currentConversation())->not->toBeNull()
        ->and(storedMessageContents($helper->currentConversation()))->toBe(['Side task', 'First side answer', 'Side task', 'Second side answer'])
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe([]);
});

test('an agent tool run outside any parent run is not a delegation', function (): void {
    $subAgent = (new RememberingSubAgent)->forUser((object) ['id' => 1]);
    $tool = new AgentTool($subAgent);

    RememberingSubAgent::fake(['First result', 'Second result']);

    $tool->handle(new Request(['task' => 'one']));
    $tool->handle(new Request(['task' => 'two']));

    expect(conversationContaining('two'))->toBe(conversationContaining('one'))
        ->and($subAgent->currentConversation())->toBe(conversationContaining('one'))
        ->and($subAgent->historySizes)->toBe([0, 2]);
});

test('a delegation stores its conversation under the ID its run reserved', function (string $mode): void {
    app()->instance(ConversationStore::class, $store = new class extends DatabaseConversationStore
    {
        public array $ids = [];

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            $this->ids[] = $id;

            return parent::storeConversation($participantType, $participantId, $title, $id);
        }
    });

    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    promptOrStream((new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user), $mode, 'Delegate');

    expect($store->ids)->toHaveCount(2)
        ->and($store->ids)->toContain(conversationContaining('one'));
})->with(['prompt', 'stream']);
