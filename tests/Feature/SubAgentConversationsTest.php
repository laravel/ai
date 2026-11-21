<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\AlwaysRememberedAgent;
use Tests\Fixtures\Agents\ContractRememberingApprovableAgent;
use Tests\Fixtures\Agents\OrchestratingAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingOrchestratingAgent;
use Tests\Fixtures\Agents\RememberingStructuredAgent;
use Tests\Fixtures\Agents\RememberingSubAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\ConversationOpeningAgentTool;

beforeEach(function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-key',
    ]);

    ApprovableNumberGenerator::$invocations = 0;
});

test('each delegation is stored in its own conversation', function (string $mode): void {
    $user = (object) ['id' => 1];
    $subAgent = (new RememberingSubAgent)->forUser($user);

    OrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    promptOrStream(new OrchestratingAgent([$subAgent]), $mode, 'Delegate both tasks');

    $conversations = DB::table('agent_conversations')->orderBy('id')->get();

    expect($conversations)->toHaveCount(2)
        ->and($conversations->pluck('participant_id')->map(fn ($id) => (int) $id)->all())->toBe([1, 1])
        ->and(storedMessageContents($conversations[0]->id))->toBe(['one', 'First result'])
        ->and(storedMessageContents($conversations[1]->id))->toBe(['two', 'Second result'])
        ->and($subAgent->historySizes)->toBe([0, 0])
        ->and($subAgent->currentConversation())->toBeNull();
})->with(['prompt', 'stream']);

test('a sub-agent held across parent runs starts a conversation for each delegation', function (string $mode): void {
    $subAgent = (new RememberingSubAgent)->forUser((object) ['id' => 1]);
    $orchestrator = new OrchestratingAgent([$subAgent]);

    OrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        'Done.',
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    promptOrStream($orchestrator, $mode, 'First run');
    promptOrStream($orchestrator, $mode, 'Second run');

    expect(DB::table('agent_conversations')->count())->toBe(2)
        ->and(conversationContaining('one'))->not->toBe(conversationContaining('two'))
        ->and($subAgent->historySizes)->toBe([0, 0])
        ->and($subAgent->currentConversation())->toBeNull();
})->with(['prompt', 'stream']);

test('a sub-agent already in a conversation shares it with every delegation', function (string $mode, bool $continued): void {
    $user = (object) ['id' => 1];

    RememberingSubAgent::fake(['Seeded', 'First result', 'Second result']);

    $subAgent = (new RememberingSubAgent)->forUser($user);

    $conversationId = $subAgent->prompt('Seed')->conversationId;

    if ($continued) {
        $subAgent = (new RememberingSubAgent)->continue($conversationId, $user);
    }

    $subAgent->historySizes = [];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    $response = promptOrStream((new RememberingOrchestratingAgent([$subAgent]))->forUser($user), $mode, 'Delegate both tasks');

    expect(storedMessageContents($conversationId))->toBe(['Seed', 'Seeded', 'one', 'First result', 'two', 'Second result'])
        ->and($subAgent->historySizes)->toBe([2, 4])
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe([$conversationId, $conversationId])
        ->and($subAgent->currentConversation())->toBe($conversationId);
})->with(['prompt', 'stream'])->with([
    'continued by the app' => true,
    'prompted directly before' => false,
]);

test('an agent tool that continues a conversation of its own before delegating is honoured', function (string $mode, string $subAgent, array $responses): void {
    $user = (object) ['id' => 1];
    $tool = new ConversationOpeningAgentTool((new $subAgent)->forUser($user));

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', class_basename($subAgent), ['task' => 'one']),
        new ToolCall('call_2', class_basename($subAgent), ['task' => 'two']),
        'Done.',
    ]);

    $subAgent::fake($responses);

    $response = promptOrStream((new RememberingOrchestratingAgent([$tool]))->forUser($user), $mode, 'Delegate both tasks');

    expect($tool->opened)->toHaveCount(2)
        ->and(DB::table('agent_conversations')->count())->toBe(3)
        ->and(conversationContaining('one'))->toBe($tool->opened[0])
        ->and(conversationContaining('two'))->toBe($tool->opened[1])
        ->and(storedMessageContents($tool->opened[0]))->toHaveCount(2)
        ->and(storedMessageContents($tool->opened[1]))->toHaveCount(2)
        ->and(array_column(storedToolCalls($response->conversationId), 'sub_conversation_id'))->toBe($tool->opened);
})->with(['prompt', 'stream'])->with([
    'a text sub-agent' => [RememberingSubAgent::class, ['First result', 'Second result']],
    'a structured sub-agent' => [RememberingStructuredAgent::class, [['symbol' => 'H'], ['symbol' => 'He']]],
]);

test('a structured sub-agent under a streaming parent is stored once for each delegation', function (): void {
    $user = (object) ['id' => 1];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingStructuredAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingStructuredAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingStructuredAgent::fake([['symbol' => 'H'], ['symbol' => 'He']]);

    $response = promptOrStream(
        (new RememberingOrchestratingAgent([(new RememberingStructuredAgent)->forUser($user)]))->forUser($user),
        'stream',
        'Delegate both tasks',
    );

    $links = array_column(storedToolCalls($response->conversationId), 'sub_conversation_id');

    expect(DB::table('agent_conversations')->count())->toBe(3)
        ->and($links)->toBe([conversationContaining('one'), conversationContaining('two')])
        ->and(storedMessageContents($links[0]))->toHaveCount(2)
        ->and(storedMessageContents($links[1]))->toHaveCount(2);
});

test('the same sub-agent behind two tools gets a conversation for each call in a step', function (): void {
    config(['ai.conversations.generate_title' => false]);

    $user = (object) ['id' => 1];
    $subAgent = (new RememberingSubAgent)->forUser($user);

    $secondTool = new class($subAgent) extends AgentTool
    {
        public function name(): string
        {
            return 'second_sub_agent';
        }
    };

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'RememberingSubAgent', 'input' => ['task' => 'one']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'second_sub_agent', 'input' => ['task' => 'two']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->pushResponse(fakeAnthropicResponse('First result'))
        ->pushResponse(fakeAnthropicResponse('Second result'))
        ->pushResponse(fakeAnthropicResponse('Done.')),
    ]);

    $response = (new RememberingOrchestratingAgent([$subAgent, $secondTool]))->forUser($user)->prompt('Delegate both tasks');

    $links = array_column(storedToolCalls($response->conversationId), 'sub_conversation_id');

    expect($links)->toBe([conversationContaining('one'), conversationContaining('two')])
        ->and($links[0])->not->toBe($links[1])
        ->and($subAgent->currentConversation())->toBeNull();
});

test('an agent delegating to itself keeps its own turn in its own conversation', function (string $mode): void {
    $agent = (new RememberingOrchestratingAgent)->forUser((object) ['id' => 1]);
    $agent->tools = [$agent];

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingOrchestratingAgent', ['task' => 'Nested task']),
        'Nested answer',
        'Top answer',
    ]);

    $response = promptOrStream($agent, $mode, 'Top task');

    $nested = conversationContaining('Nested task');

    expect(conversationContaining('Top task'))->toBe($response->conversationId)
        ->and($nested)->not->toBeNull()->not->toBe($response->conversationId)
        ->and(storedToolCalls($response->conversationId)[0]['sub_conversation_id'])->toBe($nested)
        ->and($agent->currentConversation())->toBe($response->conversationId);
})->with(['prompt', 'stream']);

test('a delegation paused on an approval is left for its own resume by the next delegation', function (string $subAgent): void {
    config(['ai.conversations.generate_title' => false]);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->pushResponse(fakeAnthropicToolUseResponse('ApprovableNumberGenerator', id: 'toolu_1'))
        ->pushResponse(fakeAnthropicResponse('Second result'))
        ->pushResponse(fakeAnthropicResponse('The number is 72019.')),
    ]);

    OrchestratingAgent::fake([
        new ToolCall('call_1', class_basename($subAgent), ['task' => 'one']),
        new ToolCall('call_2', class_basename($subAgent), ['task' => 'two']),
        'Done.',
    ]);

    $response = (new OrchestratingAgent([new $subAgent]))->prompt('Delegate both tasks');

    $paused = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

    expect($response->toolResults->pluck('result')->all())->toBe(['', 'Second result'])
        ->and($paused->status)->toBe('paused')
        ->and(json_decode($paused->steps, true)[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1'])->not->toHaveKey('result')
        // The second delegation sent only its own task, without settling the paused call...
        ->and(Http::recorded()[1][0]->data()['messages'])->toHaveCount(1);

    $resumed = (new $subAgent)->continue($paused->conversation_id)->prompt(Decisions::from(['toolu_1' => true]));

    expect($resumed->text)->toBe('The number is 72019.')
        ->and(ApprovableNumberGenerator::$invocations)->toBe(1);
})->with([
    'a remembering sub-agent' => RememberingApprovableAgent::class,
    'a sub-agent implementing the contract' => ContractRememberingApprovableAgent::class,
]);

test('a sub-agent that is always remembered is still stored', function (string $mode): void {
    OrchestratingAgent::fake([
        new ToolCall('call_1', 'AlwaysRememberedAgent', ['task' => 'one']),
        'Done.',
    ]);

    AlwaysRememberedAgent::fake(['First result']);

    promptOrStream(new OrchestratingAgent([new AlwaysRememberedAgent]), $mode, 'Delegate');

    $conversationId = conversationContaining('one');

    expect($conversationId)->not->toBeNull()
        ->and(storedMessageContents($conversationId))->toBe(['one', 'First result'])
        ->and(Conversation::find($conversationId)->participant_id)->toBeNull();
})->with(['prompt', 'stream']);

test('delegations are titled with their task instead of by the title agent', function (): void {
    $user = (object) ['id' => 1];
    $task = 'Research the history of the Laravel framework and summarize its major releases';

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => $task]),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);

    (new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user)->prompt('Delegate both tasks');

    ConversationTitleAgent::assertPromptedTimes(1);
    ConversationTitleAgent::assertPrompted('Delegate both tasks');

    expect(Conversation::find(conversationContaining('one'))->title)->toBe('one')
        ->and(Conversation::find(conversationContaining($task))->title)->toBe(Str::limit($task, 50, preserveWords: true));
});

test('an agent prompted from a custom tool keeps continuing its conversation and is titled', function (): void {
    $user = (object) ['id' => 1];
    $helper = (new RememberingAssistantAgent)->forUser($user);

    $tool = new class($helper) implements Tool
    {
        public function __construct(public RememberingAssistantAgent $helper) {}

        public function name(): string
        {
            return 'ask_helper';
        }

        public function description(): string
        {
            return 'Asks the helper two questions.';
        }

        public function handle(Request $request): string
        {
            $this->helper->prompt('First question');
            $this->helper->prompt('Second question');

            return 'Asked.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    RememberingOrchestratingAgent::fake([new ToolCall('call_1', 'ask_helper', []), 'Done.']);
    RememberingAssistantAgent::fake(['First answer', 'Second answer']);

    $response = (new RememberingOrchestratingAgent([$tool]))->forUser($user)->prompt('Ask the helper');

    ConversationTitleAgent::assertPromptedTimes(2);

    expect($helper->currentConversation())->not->toBeNull()
        ->and(storedMessageContents($helper->currentConversation()))->toBe(['First question', 'First answer', 'Second question', 'Second answer'])
        ->and(storedToolCalls($response->conversationId)[0])->not->toHaveKey('sub_conversation_id');
});

test('an agent prompted while a delegation runs keeps its own conversation', function (string $mode): void {
    $user = (object) ['id' => 1];
    $helper = (new RememberingAssistantAgent)->forUser($user);
    $helperConversations = [];

    Event::listen([PromptingAgent::class, StreamingAgent::class], function (PromptingAgent|StreamingAgent $event) use ($helper, &$helperConversations): void {
        if ($event->prompt->agent instanceof RememberingSubAgent) {
            $helperConversations[] = $helper->prompt('Side task')->conversationId;
        }
    });

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_1', 'RememberingSubAgent', ['task' => 'one']),
        new ToolCall('call_2', 'RememberingSubAgent', ['task' => 'two']),
        'Done.',
    ]);

    RememberingSubAgent::fake(['First result', 'Second result']);
    RememberingAssistantAgent::fake(['First side answer', 'Second side answer']);

    $response = promptOrStream((new RememberingOrchestratingAgent([(new RememberingSubAgent)->forUser($user)]))->forUser($user), $mode, 'Delegate both tasks');

    $links = array_column(storedToolCalls($response->conversationId), 'sub_conversation_id');

    expect($helperConversations)->toBe([$helper->currentConversation(), $helper->currentConversation()])
        ->and($links)->toHaveCount(2)
        ->and($links[0])->not->toBe($links[1])
        ->and($links)->not->toContain($helper->currentConversation());
})->with(['prompt', 'stream']);

test('interleaved parent streams sharing a sub-agent each link their own delegation', function (): void {
    $user = (object) ['id' => 1];
    $subAgent = (new RememberingSubAgent)->forUser($user);

    RememberingOrchestratingAgent::fake([
        new ToolCall('call_a', 'RememberingSubAgent', ['task' => 'task a']),
        new ToolCall('call_b', 'RememberingSubAgent', ['task' => 'task b']),
        'Done a.',
        'Done b.',
    ]);

    RememberingSubAgent::fake(['Result a', 'Result b']);

    $first = (new RememberingOrchestratingAgent([$subAgent]))->forUser($user)->stream('Run a');
    $second = (new RememberingOrchestratingAgent([$subAgent]))->forUser($user)->stream('Run b');

    $streams = [$first->getIterator(), $second->getIterator()];

    // Advance the streams one event at a time, so each is suspended inside its delegation while the other runs...
    while ($streams[0]->valid() || $streams[1]->valid()) {
        foreach ($streams as $stream) {
            if ($stream->valid()) {
                $stream->next();
            }
        }
    }

    $links = collect([$first, $second])
        ->map(fn ($response) => storedToolCalls($response->conversationId)[0])
        ->mapWithKeys(fn (array $call) => [$call['arguments']['task'] => $call['sub_conversation_id']])
        ->all();

    expect($links)->toHaveCount(2)
        ->and($links['task a'])->toBe(conversationContaining('task a'))
        ->and($links['task b'])->toBe(conversationContaining('task b'))
        ->and($subAgent->currentConversation())->toBeNull();
});
