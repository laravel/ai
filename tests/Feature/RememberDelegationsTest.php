<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ContractRememberingApprovableAgent;
use Tests\Fixtures\Agents\FailoverAssistantAgent;
use Tests\Fixtures\Agents\MiddleManagerAgent;
use Tests\Fixtures\Agents\OrchestratingAgent;
use Tests\Fixtures\Agents\RememberingOrchestratingAgent;
use Tests\Fixtures\Agents\RememberingStructuredAgent;
use Tests\Fixtures\Agents\RememberingSubAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ResearchAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\ConversationStores\InMemoryConversationStore;
use Tests\Fixtures\Tools\AgentCallingTool;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-key',
        'ai.conversations.remember_delegations' => true,
    ]);
});

dataset('sub-agents given nothing', [
    'a remembering sub-agent' => [RememberingSubAgent::class, ['First result', 'Second result']],
    'a sub-agent implementing the contract' => [ContractRememberingApprovableAgent::class, ['First result', 'Second result']],
    'a plain sub-agent' => [AssistantAgent::class, ['First result', 'Second result']],
    'a remembering structured sub-agent' => [RememberingStructuredAgent::class, [['symbol' => 'H'], ['symbol' => 'He']]],
    'a plain structured sub-agent' => [StructuredAgent::class, [['symbol' => 'H'], ['symbol' => 'He']]],
]);

/**
 * Get the IDs of the conversations stored without a participant, in order.
 *
 * @return list<string>
 */
function ownerlessConversations(): array
{
    return DB::table('agent_conversations')->whereNull('participant_id')->orderBy('id')->pluck('id')->all();
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
                ? fakeAnthropicToolUseResponse('AssistantAgent', ['task' => "run {$run} {$task}"], "toolu_{$run}_{$task}")
                : fakeAnthropicStreamToolUseResponse('AssistantAgent', ['task' => "run {$run} {$task}"], "toolu_{$run}_{$task}"));
        }

        $sequence->pushResponse($mode === 'prompt' ? fakeAnthropicResponse('Done.') : fakeAnthropicStreamResponse('Done.'));
    }

    Http::fake(['api.anthropic.com/*' => $sequence]);
}

test('delegations are not stored by default', function (string $mode, string $subAgent, array $responses): void {
    config(['ai.conversations.remember_delegations' => false]);

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', class_basename($subAgent), ['task' => 'one']),
        new ToolCall('call_2', class_basename($subAgent), ['task' => 'two']),
        'Done.',
    ]);

    $subAgent::fake($responses);

    $response = promptOrStream((new RememberingOrchestratingAgent([new $subAgent]))->forUser((object) ['id' => 1]), $mode, 'Delegate both tasks');

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe([$response->conversationId])
        ->and(storedToolCalls($response->conversationId))->toHaveCount(2)
        ->each->not->toHaveKey('sub_conversation_id');
})->with(['prompt', 'stream'])->with('sub-agents given nothing');

test('delegations of a remembered parent are stored without a participant and linked', function (string $mode, string $subAgent, array $responses): void {
    $instance = new $subAgent;

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', class_basename($subAgent), ['task' => 'one']),
        new ToolCall('call_2', class_basename($subAgent), ['task' => 'two']),
        'Done.',
    ]);

    $subAgent::fake($responses);

    $response = promptOrStream((new RememberingOrchestratingAgent([$instance]))->forUser((object) ['id' => 1]), $mode, 'Delegate both tasks');

    $ownerless = ownerlessConversations();

    expect(DB::table('agent_conversations')->where('participant_id', 1)->pluck('id')->all())->toBe([$response->conversationId])
        ->and($ownerless)->toHaveCount(2)
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe($ownerless)
        ->and(conversationContaining('one'))->toBe($ownerless[0])
        ->and(conversationContaining('two'))->toBe($ownerless[1])
        ->and(Conversation::whereKey($ownerless)->orderBy('id')->pluck('title')->all())->toBe(['one', 'two'])
        ->and(DB::table('agent_conversation_messages')->whereIn('conversation_id', $ownerless)->count())->toBe(4)
        ->and(DB::table('agent_conversation_messages')->whereIn('conversation_id', $ownerless)->distinct()->pluck('agent')->all())->toBe([$subAgent])
        ->and(DB::table('agent_conversation_messages')->whereIn('conversation_id', $ownerless)->whereNotNull('participant_id')->count())->toBe(0);

    if (method_exists($instance, 'currentConversation')) {
        expect($instance->currentConversation())->toBeNull();
    }
})->with(['prompt', 'stream'])->with('sub-agents given nothing');

test('a parent continuing a conversation without a participant is remembered with its delegations', function (): void {
    $conversationId = resolve(ConversationStore::class)->storeConversation(null, null, 'Parent');

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'AssistantAgent', ['task' => 'one']), 'Done.']);
    AssistantAgent::fake(['First result']);

    (new RememberingOrchestratingAgent([new AssistantAgent]))->continue($conversationId)->prompt('Delegate');

    expect(conversationContaining('one'))->not->toBeNull()
        ->and(storedToolCalls($conversationId)[0]['sub_conversation_id'])->toBe(conversationContaining('one'));
});

test('delegations of a parent that is not remembered are not stored', function (string $mode, string $parent): void {
    $parent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    promptOrStream(new $parent([new RememberingSubAgent]), $mode, 'Delegate both tasks');

    expect(DB::table('agent_conversations')->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(0);
})->with(['prompt', 'stream'])->with([
    'a parent that cannot remember' => OrchestratingAgent::class,
    'a remembering parent given nothing' => RememberingOrchestratingAgent::class,
]);

test('a sub-agent given a user is stored under that user', function (string $mode): void {
    $other = (object) ['id' => 2];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($other)]))->forUser((object) ['id' => 1]),
        $mode,
        'Delegate both tasks',
    );

    $conversations = DB::table('agent_conversations')->where('participant_id', 2)->orderBy('id')->pluck('id')->all();

    expect($conversations)->toHaveCount(2)
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe($conversations)
        ->and(ownerlessConversations())->toBe([]);
})->with(['prompt', 'stream']);

test('a sub-agent given a conversation shares it with every delegation, unlinked', function (string $mode): void {
    $conversationId = resolve(ConversationStore::class)->storeConversation(null, null, 'Shared');

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingSubAgent)->continue($conversationId)]))->forUser((object) ['id' => 1]),
        $mode,
        'Delegate both tasks',
    );

    expect(ownerlessConversations())->toBe([$conversationId])
        ->and(storedMessageContents($conversationId))->toBe(['one', 'First result', 'two', 'Second result'])
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe([]);
})->with(['prompt', 'stream']);

test('nested delegations are each stored and linked from the agent that delegated them', function (string $mode): void {
    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'middle_manager', ['task' => 'Deep-dive on caching']), 'Done.']);
    MiddleManagerAgent::fake([new ToolCall('call_2', 'research_agent', ['task' => 'Research cache drivers']), 'Delegated.']);
    ResearchAgent::fake(['Research result']);

    $response = promptOrStream((new RememberingOrchestratingAgent([new MiddleManagerAgent]))->forUser((object) ['id' => 1]), $mode, 'Delegate');

    $middle = conversationContaining('Deep-dive on caching');
    $leaf = conversationContaining('Research cache drivers');

    expect(ownerlessConversations())->toHaveCount(2)
        ->and(storedToolCalls($response->conversationId)[0]['sub_conversation_id'])->toBe($middle)
        ->and(storedToolCalls($middle)[0]['sub_conversation_id'])->toBe($leaf)
        ->and(storedMessageContents($leaf))->toBe(['Research cache drivers', 'Research result']);
})->with(['prompt', 'stream']);

test('an agent prompted from a custom tool is not stored', function (): void {
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
            throw_if($this->failingMethod === __FUNCTION__ && $participantType === null, new RuntimeException('The store is down.'));

            return parent::storeConversation($participantType, $participantId, $title, $id);
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
        {
            throw_if($this->failingMethod === __FUNCTION__ && $participantType === null, new RuntimeException('The store is down.'));

            return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
        }
    });

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'AssistantAgent', ['task' => 'one']), 'Done.']);
    AssistantAgent::fake(['First result']);

    $response = promptOrStream((new RememberingOrchestratingAgent([new AssistantAgent]))->forUser((object) ['id' => 1]), $mode, 'Delegate');

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

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->pushResponse(fakeGroqToolCallResponse('AssistantAgent', ['task' => 'First attempt'], 'call_1'))
        ->push(status: 429)
        ->pushResponse(fakeGroqToolCallResponse('AssistantAgent', ['task' => 'Second attempt'], 'call_2'))
        ->pushResponse(fakeGroqResponse('Done.'));

    AssistantAgent::fake(['First result', 'Second result']);

    $response = (new RememberingOrchestratingAgent([new AssistantAgent]))
        ->forUser((object) ['id' => 1])
        ->prompt('Delegate', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Done.')
        ->and(storedToolCalls($response->conversationId))->toHaveCount(1)
        ->and(storedToolCalls($response->conversationId)[0])->toMatchArray([
            'result' => 'Second result',
            'sub_conversation_id' => conversationContaining('Second attempt'),
        ]);
});

test('a sub-agent that fails over is stored and linked', function (string $mode): void {
    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Event::fake([AgentFailedOver::class]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse($mode === 'prompt' ? fakeGroqResponse('First result') : fakeGroqStreamResponse('First result'))
        ->push(status: 429)
        ->pushResponse($mode === 'prompt' ? fakeGroqResponse('Second result') : fakeGroqStreamResponse('Second result'));

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'FailoverAssistantAgent', ['task' => 'one']),
        new ToolCall('call_2', 'FailoverAssistantAgent', ['task' => 'two']),
        'Done.',
    ]);

    $response = promptOrStream((new RememberingOrchestratingAgent([new FailoverAssistantAgent]))->forUser((object) ['id' => 1]), $mode, 'Delegate both tasks');

    Event::assertDispatchedTimes(AgentFailedOver::class, 2);

    expect(storedToolCalls($response->conversationId))->sequence(
        fn ($call) => $call->toMatchArray(['result' => 'First result', 'sub_conversation_id' => conversationContaining('one')]),
        fn ($call) => $call->toMatchArray(['result' => 'Second result', 'sub_conversation_id' => conversationContaining('two')]),
    )->and(ownerlessConversations())->toHaveCount(2);
})->with(['prompt', 'stream']);

test('queued parents store and link their delegations', function (): void {
    config(['ai.conversations.generate_title' => false]);

    fakeDelegatingRuns('prompt', runs: 2);

    AssistantAgent::fake(['Result 1', 'Result 2', 'Result 3', 'Result 4']);

    foreach ([1, 2] as $run) {
        (new RememberingOrchestratingAgent([new AssistantAgent]))->forUser((object) ['id' => 1])->queue('Delegate both tasks');
    }

    $parents = DB::table('agent_conversations')->where('participant_id', 1)->orderBy('id')->pluck('id')->all();

    expect($parents)->toHaveCount(2)
        ->and(ownerlessConversations())->toHaveCount(4)
        ->and(array_column(storedToolCalls($parents[0]), 'sub_conversation_id'))->toBe([conversationContaining('run 1 one'), conversationContaining('run 1 two')])
        ->and(array_column(storedToolCalls($parents[1]), 'sub_conversation_id'))->toBe([conversationContaining('run 2 one'), conversationContaining('run 2 two')]);
});

test('broadcast parents store and link their delegations', function (string $method): void {
    config(['ai.conversations.generate_title' => false]);

    Event::fake([AnonymousEvent::class]);

    fakeDelegatingRuns('stream', runs: 2);

    AssistantAgent::fake(['Result 1', 'Result 2', 'Result 3', 'Result 4']);

    foreach ([1, 2] as $run) {
        $orchestrator = (new RememberingOrchestratingAgent([new AssistantAgent]))->forUser((object) ['id' => 1]);

        $method === 'broadcastNow'
            ? iterator_to_array($orchestrator->broadcastNow('Delegate both tasks', new Channel("run-{$run}")))
            : $orchestrator->broadcastOnQueue('Delegate both tasks', new Channel("run-{$run}"));
    }

    $parents = DB::table('agent_conversations')->where('participant_id', 1)->orderBy('id')->pluck('id')->all();
    $links = [...array_column(storedToolCalls($parents[0]), 'sub_conversation_id'), ...array_column(storedToolCalls($parents[1]), 'sub_conversation_id')];

    expect($links)->toBe([
        conversationContaining('run 1 one'),
        conversationContaining('run 1 two'),
        conversationContaining('run 2 one'),
        conversationContaining('run 2 two'),
    ])->and(ownerlessConversations())->toHaveCount(4);

    $broadcasts = Event::dispatched(AnonymousEvent::class)->map(fn (array $event) => json_encode($event[0]->broadcastWith()))->join("\n");

    expect($broadcasts)->toContain('Result 1')->not->toContain('sub_conversation');

    foreach ($links as $link) {
        expect($broadcasts)->not->toContain($link);
    }
})->with(['broadcastNow', 'broadcastOnQueue']);

test('no delegation state outlives the parent run', function (Closure $run): void {
    // Earlier tests may leave runs to the cycle collector, which forgets them as it collects them...
    gc_collect_cycles();

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']), 'Done.']);
    RememberingSubAgent::fake(['First result']);

    $run((new RememberingOrchestratingAgent([new RememberingSubAgent]))->forUser((object) ['id' => 1]));

    expect((new ReflectionProperty(AgentTool::class, 'delegations'))->getValue())->toBe([])
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

test('a delegation that stores nothing is not linked to an earlier delegation of the same tool', function (): void {
    config(['ai.conversations.remember_delegations' => true]);

    $user = (object) ['id' => 1];
    $tool = new AgentTool(new ResearchAgent);
    $listenerFails = true;

    // Providers that omit tool call IDs give every call the same empty ID...
    RememberingOrchestratingAgent::fake([
        new ToolCall('', 'research_agent', ['task' => 'one']),
        new ToolCall('', 'research_agent', ['task' => 'two']),
        'Done.',
    ]);
    ResearchAgent::fake(['First result', 'Second result']);

    Event::listen(ToolInvoked::class, function () use (&$listenerFails): void {
        throw_if($listenerFails, new RuntimeException('The listener failed.'));
    });

    expect(fn () => (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate'))
        ->toThrow(RuntimeException::class, 'The listener failed.');

    $listenerFails = false;
    config(['ai.conversations.remember_delegations' => false]);

    $response = (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Delegate again');

    expect(storedToolCalls($response->conversationId)[0])->toMatchArray(['id' => '', 'result' => 'Second result'])
        ->not->toHaveKey('sub_conversation_id');
});

test('delegations are stored by a custom conversation store', function (string $mode): void {
    config(['ai.conversations.remember_delegations' => true]);

    app()->instance(ConversationStore::class, $store = new InMemoryConversationStore);

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'one']),
        new ToolCall('call_2', 'research_agent', ['task' => 'two']),
        'Done.',
    ]);
    ResearchAgent::fake(['First result', 'Second result']);

    $response = promptOrStream((new RememberingOrchestratingAgent([new ResearchAgent]))->forUser((object) ['id' => 1]), $mode, 'Delegate both tasks');

    $conversationOf = fn (string $content) => collect($store->messages)->firstWhere('content', $content)['conversation_id'];

    expect($store->conversations)->toHaveCount(3)
        ->and($conversationOf('Delegate both tasks'))->toBe($response->conversationId)
        ->and($conversationOf('one'))->not->toBe($conversationOf('two'))
        ->and($conversationOf('First result'))->toBe($conversationOf('one'));
})->with(['prompt', 'stream']);
