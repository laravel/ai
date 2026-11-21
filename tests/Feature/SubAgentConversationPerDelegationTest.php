<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

class ScSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        messages as storedMessages;
    }

    /** @var list<array{?string, int}> */
    public static array $histories = [];

    public function instructions(): string
    {
        return 'Sub-agent.';
    }

    public function messages(): iterable
    {
        $messages = [...$this->storedMessages()];

        static::$histories[] = [$this->currentConversation(), count($messages)];

        return $messages;
    }
}

class ScStructuredSub implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Structured sub-agent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->string()->required()];
    }
}

#[Provider('anthropic')]
class ScApprovingSub implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Always use the tool.';
    }

    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }
}

#[Provider('anthropic')]
class ScContractApprovingSub implements Agent, HasTools, Laravel\Ai\Contracts\RemembersConversations
{
    use Promptable;

    public ?string $id = null;

    public ?object $user = null;

    public function instructions(): string
    {
        return 'Always use the tool.';
    }

    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator];
    }

    public function messages(): iterable
    {
        return $this->id === null ? [] : resolve(ConversationStore::class)->getLatestConversationMessages($this->id, 100)->all();
    }

    public function forParticipant(object $participant): static
    {
        [$this->id, $this->user] = [null, $participant];

        return $this;
    }

    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        [$this->id, $this->user] = [$conversationId, $as];

        return $this;
    }

    public function continueOrStart(?string $conversationId, object $as): static
    {
        return $conversationId === null ? $this->forParticipant($as) : $this->continue($conversationId, $as);
    }

    public function continueLastConversation(object $as): static
    {
        return $this;
    }

    public function currentConversation(): ?string
    {
        return $this->id;
    }

    public function hasConversationParticipant(): bool
    {
        return $this->user !== null;
    }

    public function conversationParticipant(): ?object
    {
        return $this->user;
    }
}

#[MaxSteps(6)]
class ScOrchestrator implements Agent, HasTools
{
    use Promptable;

    public function __construct(public Closure $resolveTools)
    {
        //
    }

    public function instructions(): string
    {
        return 'Delegate.';
    }

    public function tools(): iterable
    {
        return ($this->resolveTools)();
    }
}

function scRun(Agent $agent, string $mode, string $text): mixed
{
    if ($mode === 'prompt') {
        return $agent->prompt($text);
    }

    $stream = $agent->stream($text);

    foreach ($stream as $event) {
        //
    }

    return $stream;
}

function scConversations(): array
{
    return DB::table('agent_conversations')->orderBy('id')->pluck('id')->all();
}

function scMessages(string $conversationId): array
{
    return DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->orderBy('id')->get()->all();
}

function scCountTitles(): object
{
    $counter = (object) ['count' => 0];

    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use ($counter): void {
        if ($event->prompt->agent instanceof ConversationTitleAgent) {
            $counter->count++;
        }
    });

    return $counter;
}

beforeEach(function (): void {
    ScSub::$histories = [];
    ApprovableNumberGenerator::$invocations = 0;
    ApprovableNumberGenerator::$approvalChecks = 0;
});

test('case 1: two delegations in one parent run each get their own conversation', function (string $mode): void {
    $titles = scCountTitles();

    $user = (object) ['id' => 1];

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScSub', ['task' => 'two']),
        'done',
    ]);

    ScSub::fake(['r1', 'r2']);

    scRun(new ScOrchestrator(fn () => [(new ScSub)->forUser($user)]), $mode, 'go');

    $conversations = scConversations();

    dump("case1 {$mode}: conversations=".count($conversations).' titles='.$titles->count.' histories='.json_encode(array_column(ScSub::$histories, 1)));

    expect($conversations)->toHaveCount(2)
        ->and(scMessages($conversations[0]))->toHaveCount(2)
        ->and(scMessages($conversations[1]))->toHaveCount(2)
        ->and(array_column(ScSub::$histories, 1))->toBe([0, 0]);
})->with(['prompt', 'stream']);

test('case 2: a sub-agent held as a property across two parent runs gets a conversation per delegation', function (string $mode): void {
    $titles = scCountTitles();

    $user = (object) ['id' => 1];

    $sub = (new ScSub)->forUser($user);

    $orchestrator = new ScOrchestrator(fn () => [$sub]);

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScSub', ['task' => 'one']),
        'done 1',
        new ToolCall('call_2', 'ScSub', ['task' => 'two']),
        'done 2',
    ]);

    ScSub::fake(['r1', 'r2']);

    scRun($orchestrator, $mode, 'run 1');
    scRun($orchestrator, $mode, 'run 2');

    $conversations = scConversations();

    dump("case2 {$mode}: conversations=".count($conversations).' titles='.$titles->count.' histories='.json_encode(array_column(ScSub::$histories, 1)).' instance conversation='.var_export($sub->currentConversation(), true));

    expect($conversations)->toHaveCount(2)
        ->and(array_column(ScSub::$histories, 1))->toBe([0, 0]);
})->with(['prompt', 'stream']);

test('case 3: a caller-set conversation is shared by every delegation', function (string $mode): void {
    $user = (object) ['id' => 1];

    ScSub::fake(['seed', 'r1', 'r2']);

    $seeded = (new ScSub)->forUser($user)->prompt('seed');

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScSub', ['task' => 'two']),
        'done',
    ]);

    ScSub::$histories = [];

    $sub = (new ScSub)->continue($seeded->conversationId, $user);

    scRun(new ScOrchestrator(fn () => [$sub]), $mode, 'go');

    dump("case3 {$mode}: conversations=".count(scConversations()).' histories='.json_encode(array_column(ScSub::$histories, 1)));

    expect(scConversations())->toBe([$seeded->conversationId])
        ->and(scMessages($seeded->conversationId))->toHaveCount(6)
        ->and(array_column(ScSub::$histories, 1))->toBe([2, 4])
        ->and($sub->currentConversation())->toBe($seeded->conversationId);
})->with(['prompt', 'stream']);

test('case 4: a structured sub-agent stores exactly one conversation per delegation when the parent streams', function (): void {
    $titles = scCountTitles();

    $user = (object) ['id' => 1];

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScStructuredSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScStructuredSub', ['task' => 'two']),
        'done',
    ]);

    ScStructuredSub::fake([['answer' => 'a'], ['answer' => 'b']]);

    scRun(new ScOrchestrator(fn () => [(new ScStructuredSub)->forUser($user)]), 'stream', 'go');

    $conversations = scConversations();

    dump('case4: conversations='.count($conversations).' titles='.$titles->count);

    expect($conversations)->toHaveCount(2)
        ->and(scMessages($conversations[0]))->toHaveCount(2)
        ->and(scMessages($conversations[1]))->toHaveCount(2);
});

function scAnthropicToolUse(string $id): array
{
    return [
        'id' => 'msg_'.$id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function scAnthropicText(string $text): array
{
    return [
        'id' => 'msg_'.md5($text),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

test('case 5: an ownerless sub-agent paused in delegation 1 is left intact by delegation 2', function (): void {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response(scAnthropicToolUse('toolu_s1')),
            Http::response(scAnthropicText('two done')),
            Http::response(scAnthropicText('The number is 72019.')),
        ]),
    ]);

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScApprovingSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScApprovingSub', ['task' => 'two']),
        'done',
    ]);

    $response = (new ScOrchestrator(fn () => [new ScApprovingSub]))->prompt('go');

    expect($response->toolResults->pluck('result')->all())->toBe(['', 'two done']);

    $conversations = scConversations();

    $recorded = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    dump('case5: conversations='.count($conversations).' delegation2 request messages='.json_encode($recorded[1]['messages']));

    expect($conversations)->toHaveCount(1);

    $rows = scMessages($conversations[0]);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->status)->toBe('paused');

    $steps = json_decode($rows[1]->steps, true);

    expect($steps[0]['replay_blocks'])->not->toBeEmpty()
        ->and($steps[0]['tool_calls'][0])->not->toHaveKey('result');

    // Delegation 2 sent only its own task, with no denied placeholder for delegation 1's paused call...
    expect($recorded[1]['messages'])->toHaveCount(1)
        ->and(json_encode($recorded[1]['messages']))->not->toContain('toolu_s1');

    // The paused turn still resumes...
    $resumed = (new ScApprovingSub)
        ->continue($conversations[0])
        ->prompt(Decisions::from(['toolu_s1' => true]));

    expect($resumed->text)->toBe('The number is 72019.')
        ->and(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('case 5b: an ownerless contract-only sub-agent paused in delegation 1 is left intact by delegation 2', function (): void {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response(scAnthropicToolUse('toolu_s1')),
            Http::response(scAnthropicText('two done')),
        ]),
    ]);

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScContractApprovingSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScContractApprovingSub', ['task' => 'two']),
        'done',
    ]);

    $sub = new ScContractApprovingSub;

    (new ScOrchestrator(fn () => [$sub]))->prompt('go');

    $recorded = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    dump('case5b: conversations='.count(scConversations()).' delegation2 request messages='.count($recorded[1]['messages']).' instance id='.var_export($sub->id, true));

    expect(scConversations())->toHaveCount(1)
        ->and($recorded[1]['messages'])->toHaveCount(1)
        ->and($sub->id)->toBeNull();
});

test('case 6: a top-level agent prompted twice on the same instance continues its conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    ScSub::fake(['a', 'b']);

    $agent = (new ScSub)->forUser($user);

    $first = scRun($agent, $mode, 'first');
    $second = scRun($agent, $mode, 'second');

    expect(scConversations())->toHaveCount(1)
        ->and($first->conversationId)->toBe($second->conversationId)
        ->and($agent->currentConversation())->toBe($first->conversationId)
        ->and(array_column(ScSub::$histories, 1))->toBe([0, 2])
        ->and(scMessages($first->conversationId))->toHaveCount(4);
})->with(['prompt', 'stream']);

test('case 2b (informational): a sub-agent instance that ran top-level before being wrapped', function (): void {
    $user = (object) ['id' => 1];

    $sub = (new ScSub)->forUser($user);

    ScSub::fake(['top', 'r1', 'r2']);

    $top = $sub->prompt('top-level first');

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScSub', ['task' => 'two']),
        'done',
    ]);

    (new ScOrchestrator(fn () => [$sub]))->prompt('go');

    dump('case2b: conversations='.count(scConversations()).' histories='.json_encode(array_column(ScSub::$histories, 1)).' top conversation rows='.count(scMessages($top->conversationId)));

    expect(true)->toBeTrue();
});

test('title calls per delegation (informational)', function (): void {
    $titles = scCountTitles();

    $user = (object) ['id' => 1];

    ScOrchestrator::fake([
        new ToolCall('call_1', 'ScSub', ['task' => 'one']),
        new ToolCall('call_2', 'ScSub', ['task' => 'two']),
        new ToolCall('call_3', 'ScSub', ['task' => 'three']),
        'done',
    ]);

    ScSub::fake(['r1', 'r2', 'r3']);

    (new ScOrchestrator(fn () => [(new ScSub)->forUser($user)]))->prompt('go');

    dump('titles: delegations=3 conversations='.count(scConversations()).' title calls='.$titles->count.' titles='.json_encode(DB::table('agent_conversations')->pluck('title')->all()));

    expect(true)->toBeTrue();
});

test('a custom tool that prompts the same agent twice (informational)', function (): void {
    $user = (object) ['id' => 1];

    $helper = (new ScSub)->forUser($user);

    $tool = new class($helper) implements Tool
    {
        public function __construct(public ScSub $helper) {}

        public function description(): string
        {
            return 'Asks the helper twice.';
        }

        public function handle(Request $request): string
        {
            $first = $this->helper->prompt('first');

            $idAfterFirst = $this->helper->currentConversation();

            $second = $this->helper->prompt('second');

            return json_encode([$first->conversationId, $idAfterFirst, $second->conversationId]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    ScOrchestrator::fake([
        new ToolCall('call_1', class_basename($tool), []),
        'done',
    ]);

    ScSub::fake(['a', 'b']);

    $response = (new ScOrchestrator(fn () => [$tool]))->prompt('go');

    dump('custom tool: result='.$response->toolResults->first()?->result.' conversations='.count(scConversations()).' histories='.json_encode(array_column(ScSub::$histories, 1)));

    expect(true)->toBeTrue();
});

class ScAppOpenedAgentTool extends AgentTool
{
    /** @var list<string> */
    public array $opened = [];

    public function handle(ToolRequest $request): string
    {
        $this->openConversation($request);

        return parent::handle($request);
    }

    public function stream(ToolRequest $request): Generator
    {
        if (! $this->agent() instanceof HasStructuredOutput) {
            $this->openConversation($request);
        }

        return yield from parent::stream($request);
    }

    protected function openConversation(ToolRequest $request): void
    {
        $this->opened[] = $id = resolve(ConversationStore::class)->storeConversation(null, null, 'app: '.$request['task']);

        $this->agent()->continue($id, $this->agent()->conversationParticipant());
    }
}

test('case 7: an AgentTool subclass that continues an app-created conversation before delegating is honoured', function (string $mode, string $sub): void {
    $user = (object) ['id' => 1];

    ScOrchestrator::fake([
        new ToolCall('call_1', $sub, ['task' => 'one']),
        new ToolCall('call_2', $sub, ['task' => 'two']),
        'done',
    ]);

    $sub === 'ScSub' ? ScSub::fake(['r1', 'r2']) : ScStructuredSub::fake([['answer' => 'a'], ['answer' => 'b']]);

    $agent = (new $sub)->forUser($user);

    $tool = new ScAppOpenedAgentTool($agent);

    scRun(new ScOrchestrator(fn () => [$tool]), $mode, 'go');

    dump("case7 {$mode} {$sub}: conversations=".count(scConversations()).' opened='.count($tool->opened).' rows='.json_encode(array_map(fn ($id) => count(scMessages($id)), $tool->opened)).' histories='.json_encode(array_column(ScSub::$histories, 1)).' instance conversation='.var_export($agent->currentConversation(), true));

    expect(scConversations())->toBe($tool->opened)
        ->and($tool->opened)->toHaveCount(2)
        ->and(scMessages($tool->opened[0]))->toHaveCount(2)
        ->and(scMessages($tool->opened[1]))->toHaveCount(2)
        // proto4: nothing restores the instance, so it keeps the conversation the subclass itself set last...
        ->and($agent->currentConversation())->toBe($tool->opened[1]);

    if ($sub === 'ScSub') {
        expect(array_column(ScSub::$histories, 1))->toBe([0, 0]);
    }
})->with(['prompt', 'stream'])->with(['ScSub', 'ScStructuredSub']);
