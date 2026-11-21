<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Ai\Vercel\Vercel;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;

#[Provider('anthropic')]
class LkSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Sub-agent.';
    }
}

class LkStructuredSub implements Agent, Conversational, HasStructuredOutput
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

class LkPlainSub implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Not conversational.';
    }
}

#[Provider('anthropic')]
#[MaxSteps(4)]
class LkToolSub implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Uses a tool.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}

#[Provider('anthropic')]
class LkApprovingSub implements Agent, Conversational, HasTools
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
#[MaxSteps(6)]
class LkParent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

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

class LkAppOpenedAgentTool extends AgentTool
{
    /** @var list<string> */
    public array $opened = [];

    public function handle(ToolRequest $request): string
    {
        $this->opened[] = $id = resolve(ConversationStore::class)->storeConversation(null, null, 'app: '.$request['task']);

        $this->agent()->continue($id, $this->agent()->conversationParticipant());

        return parent::handle($request);
    }

    public function stream(ToolRequest $request): Generator
    {
        if (! $this->agent() instanceof HasStructuredOutput) {
            $this->opened[] = $id = resolve(ConversationStore::class)->storeConversation(null, null, 'app: '.$request['task']);

            $this->agent()->continue($id, $this->agent()->conversationParticipant());
        }

        return yield from parent::stream($request);
    }
}

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');
});

function lkRun(Agent $agent, string $mode, string $text): mixed
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

/** The parent's stored tool call entries, across all of its assistant rows. */
function lkParentToolCalls(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)->where('role', 'assistant')->orderBy('id')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->flatMap(fn (array $step) => $step['tool_calls']))
        ->values()->all();
}

/** The sub-conversation whose user row holds the given task. */
function lkConversationForTask(string $task): ?string
{
    return DB::table('agent_conversation_messages')->where('role', 'user')->where('content', $task)->value('conversation_id');
}

function lkOutput(SymfonyResponse $response): string
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    return $output;
}

function lkAnthropicText(string $text): array
{
    return [
        'id' => 'msg_'.md5($text), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function lkAnthropicToolUse(string $id, string $name, array $input = []): array
{
    return [
        'id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) $input]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function lkAnthropicStream(array $block, string $stopReason, ?string $delta = null): string
{
    $deltaEvent = $block['type'] === 'text'
        ? ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]]
        : ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $delta]];

    return implode("\n\n", array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event), [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.md5(json_encode($block)), 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => $block],
        $deltaEvent,
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ]))."\n\n";
}

function lkSse(string $body): array
{
    return [$body, 200, ['Content-Type' => 'text/event-stream']];
}

test('L1: each delegation\'s sub-conversation id is stored on the parent\'s tool call', function (string $mode): void {
    $user = (object) ['id' => 1];

    LkParent::fake([
        new ToolCall('call_1', 'LkSub', ['task' => 'one']),
        new ToolCall('call_2', 'LkSub', ['task' => 'two']),
        'done',
    ]);

    LkSub::fake(['r1', 'r2']);

    $parent = (new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user);

    $response = lkRun($parent, $mode, 'go');

    $calls = lkParentToolCalls($response->conversationId);

    $results = $mode === 'prompt'
        ? $response->toolResults
        : $response->events->whereInstanceOf(ToolResultEvent::class)->reject->preliminary->map->toolResult->values();

    dump("L1 {$mode}: ".json_encode($calls));

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toMatchArray(['id' => 'call_1', 'result' => 'r1', 'sub_conversation_id' => lkConversationForTask('one')])
        ->and($calls[1])->toMatchArray(['id' => 'call_2', 'result' => 'r2', 'sub_conversation_id' => lkConversationForTask('two')])
        ->and($calls[0]['sub_conversation_id'])->not->toBe($calls[1]['sub_conversation_id'])
        // In process, the parent's response carries the link too...
        ->and($results->pluck('subConversationId')->all())->toBe([$calls[0]['sub_conversation_id'], $calls[1]['sub_conversation_id']])
        // ...but never in its serialized form...
        ->and(json_encode($results))->not->toContain($calls[0]['sub_conversation_id'])
        ->and(json_encode($mode === 'prompt' ? $response->steps : $response->events))->not->toContain($calls[0]['sub_conversation_id'])
        // The parent's model finds exactly the linked sub-conversations.
        ->and(Conversation::find($response->conversationId)->subConversations()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$calls[0]['sub_conversation_id'], $calls[1]['sub_conversation_id']])->sort()->values()->all());
})->with(['prompt', 'stream']);

test('L2: a structured sub-agent under a streaming parent is linked through the handle() fallback', function (): void {
    $user = (object) ['id' => 1];

    LkParent::fake([
        new ToolCall('call_1', 'LkStructuredSub', ['task' => 'one']),
        'done',
    ]);

    LkStructuredSub::fake([['answer' => 'a']]);

    $response = lkRun((new LkParent(fn () => [(new LkStructuredSub)->forUser($user)]))->forUser($user), 'stream', 'go');

    $calls = lkParentToolCalls($response->conversationId);

    expect($calls[0]['sub_conversation_id'])->toBe(lkConversationForTask('one'))->not->toBeNull()
        ->and(DB::table('agent_conversations')->count())->toBe(2);
});

test('L3: a shared conversation, an app-opened conversation and a non-conversational sub-agent', function (string $mode, bool $rememberDelegations): void {
    Config::set('ai.conversations.remember_delegations', $rememberDelegations);

    $user = (object) ['id' => 1];

    LkSub::fake(['seed', 'r1', 'r2', 'r3', 'r4']);
    LkPlainSub::fake(['p1']);

    $seeded = (new LkSub)->forUser($user)->prompt('seed');

    $shared = (new LkSub)->continue($seeded->conversationId, $user);
    $appOpened = new LkAppOpenedAgentTool((new LkSub)->forUser($user));

    LkParent::fake([
        new ToolCall('call_s1', 'LkSub', ['task' => 's1']),
        new ToolCall('call_s2', 'LkSub', ['task' => 's2']),
        'done',
    ]);

    $sharedRun = lkRun((new LkParent(fn () => [$shared]))->forUser($user), $mode, 'go shared');

    LkParent::fake([
        new ToolCall('call_a1', 'LkSub', ['task' => 'a1']),
        new ToolCall('call_a2', 'LkSub', ['task' => 'a2']),
        new ToolCall('call_p1', 'LkPlainSub', ['task' => 'p1']),
        'done',
    ]);

    $appRun = lkRun((new LkParent(fn () => [$appOpened, new LkPlainSub]))->forUser($user), $mode, 'go app');

    $sharedCalls = lkParentToolCalls($sharedRun->conversationId);
    $appCalls = lkParentToolCalls($appRun->conversationId);

    expect(array_column($sharedCalls, 'sub_conversation_id'))->toBe([$seeded->conversationId, $seeded->conversationId])
        ->and(array_column(array_slice($appCalls, 0, 2), 'sub_conversation_id'))->toBe($appOpened->opened)
        ->and($appCalls[2]['result'])->toBe('p1');

    if ($rememberDelegations) {
        // The parent is remembered, so the plain sub-agent's delegation is stored as an ownerless sub-conversation...
        expect($appCalls[2]['sub_conversation_id'])->toBe(lkConversationForTask('p1'))->not->toBeNull()
            ->and(DB::table('agent_conversations')->where('id', $appCalls[2]['sub_conversation_id'])->value('participant_id'))->toBeNull();
    } else {
        // Switch off: a plain sub-agent is stored as on 1.x, which is never...
        expect($appCalls[2])->not->toHaveKey('sub_conversation_id')
            ->and(lkConversationForTask('p1'))->toBeNull();
    }
})->with(['prompt', 'stream'])->with(['remember_delegations off' => false, 'remember_delegations on' => true]);

test('L4: the provider request carries only the sub-agent text, before and after the parent is continued (Anthropic over Http::fake)', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(lkAnthropicToolUse('toolu_p1', 'LkSub', ['task' => 'one']))
        ->push(lkAnthropicText('sub says hi'))
        ->push(lkAnthropicText('final'))
        ->push(lkAnthropicText('again done')),
    ]);

    $parent = (new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user);

    $first = $parent->prompt('go');

    $subId = lkConversationForTask('one');

    $calls = lkParentToolCalls($first->conversationId);

    expect($calls[0])->toMatchArray(['id' => 'toolu_p1', 'result' => 'sub says hi', 'sub_conversation_id' => $subId]);

    // Continue the parent later, from storage, on a fresh instance...
    $second = (new LkParent(fn () => [(new LkSub)->forUser($user)]))->continue($first->conversationId, $user)->prompt('again');

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    $toolResultBlock = fn (array $request) => collect($request['messages'])->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : [])->firstWhere('type', 'tool_result');

    dump('L4 request 3 tool_result: '.json_encode($toolResultBlock($requests[2])));
    dump('L4 request 4 tool_result (replayed from storage): '.json_encode($toolResultBlock($requests[3])));

    expect($second->text)->toBe('again done')
        ->and($requests)->toHaveCount(4)
        ->and($toolResultBlock($requests[2]))->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_p1', 'content' => 'sub says hi'])
        ->and($toolResultBlock($requests[3]))->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_p1', 'content' => 'sub says hi'])
        ->and(json_encode($requests->all()))->not->toContain($subId)
        ->and(json_encode($requests->all()))->not->toContain('sub_conversation_id');

    // The key survives the parent's next turn untouched and hydrates without it...
    expect(lkParentToolCalls($first->conversationId)[0]['sub_conversation_id'])->toBe($subId);

    $hydrated = collect($parent->continue($first->conversationId, $user)->messages())
        ->whereInstanceOf(ToolResultMessage::class)->flatMap->toolResults->first();

    expect($hydrated)->toBeInstanceOf(ToolResult::class)
        ->and($hydrated->subConversationId)->toBeNull()
        ->and(ToolResult::fromArray($calls[0])->toArray())->not->toHaveKey('sub_conversation_id');
});

test('L5: the streamed provider request carries only the sub-agent text (Anthropic SSE over Http::fake)', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...lkSse(lkAnthropicStream(['type' => 'tool_use', 'id' => 'toolu_p1', 'name' => 'LkSub', 'input' => (object) []], 'tool_use', '{"task":"one"}')))
        ->push(...lkSse(lkAnthropicStream(['type' => 'text', 'text' => ''], 'end_turn', 'sub streams hi')))
        ->push(...lkSse(lkAnthropicStream(['type' => 'text', 'text' => ''], 'end_turn', 'final'))),
    ]);

    $stream = lkRun((new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user), 'stream', 'go');

    $subId = lkConversationForTask('one');

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    $toolResultBlock = collect($requests[2]['messages'])->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : [])->firstWhere('type', 'tool_result');

    dump('L5 streamed request 3 tool_result: '.json_encode($toolResultBlock));

    expect($subId)->not->toBeNull()
        ->and(lkParentToolCalls($stream->conversationId)[0]['sub_conversation_id'])->toBe($subId)
        ->and($toolResultBlock)->toMatchArray(['tool_use_id' => 'toolu_p1', 'content' => 'sub streams hi'])
        ->and(json_encode($requests->all()))->not->toContain($subId);

    // Stream events: final tool result carries the id in process only, never in its array/JSON form...
    $final = $stream->events->whereInstanceOf(ToolResultEvent::class)->reject->preliminary->first();

    expect($final->toolResult->subConversationId)->toBe($subId)
        ->and($stream->events->map(fn ($event) => (string) $event)->join("\n"))->not->toContain($subId);
});

test('L6: Vercel and AG-UI protocol output, Vercel::toUiMessages() and ToolInvoked carry no sub-conversation id', function (): void {
    $user = (object) ['id' => 1];

    $invoked = [];

    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked[] = $event->result;
    });

    $run = function (string $protocol) use ($user): array {
        LkParent::fake([new ToolCall('call_1', 'LkSub', ['task' => "task {$protocol}"]), 'done']);
        LkSub::fake(["r {$protocol}"]);

        $stream = (new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user)->stream('go');

        $output = lkOutput($stream->usingProtocol($protocol === 'vercel' ? new VercelDataProtocol : new AgentUserInteractionProtocol)->toResponse(request()));

        return [$output, lkConversationForTask("task {$protocol}"), $stream->conversationId];
    };

    [$vercel, $vercelSub, $vercelParent] = $run('vercel');
    [$agUi, $agUiSub, $agUiParent] = $run('agui');

    $ui = Vercel::toUiMessages((new LkParent(fn () => []))->continue($vercelParent, $user)->messages());

    dump('L6 vercel output: '.$vercel);

    expect($vercelSub)->not->toBeNull()->and($agUiSub)->not->toBeNull()
        ->and(lkParentToolCalls($vercelParent)[0]['sub_conversation_id'])->toBe($vercelSub)
        ->and(lkParentToolCalls($agUiParent)[0]['sub_conversation_id'])->toBe($agUiSub)
        ->and($vercel)->toContain('r vercel')->not->toContain($vercelSub)
        ->and($agUi)->toContain('r agui')->not->toContain($agUiSub)
        ->and(json_encode($ui))->toContain('r vercel')->not->toContain($vercelSub)
        ->and($invoked)->toBe(['r vercel', 'r agui']);
});

test('L7: a sub-agent that fails after its first step links its failed sub-conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...($mode === 'prompt'
            ? [lkAnthropicToolUse('toolu_s1', 'FixedNumberGenerator')]
            : lkSse(lkAnthropicStream(['type' => 'tool_use', 'id' => 'toolu_s1', 'name' => 'FixedNumberGenerator', 'input' => (object) []], 'tool_use', '{}'))))
        ->pushStatus(500)->pushStatus(500)->pushStatus(500),
    ]);

    LkParent::fake([new ToolCall('call_1', 'LkToolSub', ['task' => 'one']), 'done']);

    $response = lkRun((new LkParent(fn () => [(new LkToolSub)->forUser($user)]))->forUser($user), $mode, 'go');

    $calls = lkParentToolCalls($response->conversationId);

    $subId = lkConversationForTask('one');

    dump("L7 {$mode}: ".json_encode($calls));

    expect($calls[0]['failed'] ?? false)->toBeTrue()
        ->and($subId)->not->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $subId)->where('role', 'assistant')->value('status'))->toBe('failed')
        ->and($calls[0]['sub_conversation_id'] ?? null)->toBe($subId);
})->with(['prompt', 'stream']);

test('L8: a sub-agent that fails before its first step links nothing', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()->pushStatus(500)->pushStatus(500)->pushStatus(500)]);

    LkParent::fake([new ToolCall('call_1', 'LkToolSub', ['task' => 'one']), 'done']);

    $response = lkRun((new LkParent(fn () => [(new LkToolSub)->forUser($user)]))->forUser($user), $mode, 'go');

    $calls = lkParentToolCalls($response->conversationId);

    dump("L8 {$mode}: ".json_encode($calls).' conversations='.DB::table('agent_conversations')->count());

    expect($calls[0]['failed'] ?? false)->toBeTrue()
        ->and($calls[0])->not->toHaveKey('sub_conversation_id')
        ->and(DB::table('agent_conversations')->count())->toBe(1);
})->with(['prompt', 'stream']);

test('L9: an ownerless sub-agent that pauses on an approval links its paused sub-conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...($mode === 'prompt'
            ? [lkAnthropicToolUse('toolu_s1', 'ApprovableNumberGenerator')]
            : lkSse(lkAnthropicStream(['type' => 'tool_use', 'id' => 'toolu_s1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []], 'tool_use', '{}')))),
    ]);

    LkParent::fake([new ToolCall('call_1', 'LkApprovingSub', ['task' => 'one']), 'done']);

    $response = lkRun((new LkParent(fn () => [new LkApprovingSub]))->forUser($user), $mode, 'go');

    $calls = lkParentToolCalls($response->conversationId);

    $subId = lkConversationForTask('one');

    dump("L9 {$mode}: ".json_encode($calls));

    expect($calls[0]['result'])->toBe('')
        ->and($subId)->not->toBeNull()
        ->and(DB::table('agent_conversations')->where('id', $subId)->value('participant_id'))->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $subId)->where('role', 'assistant')->value('status'))->toBe('paused')
        ->and($calls[0]['sub_conversation_id'] ?? null)->toBe($subId);
})->with(['prompt', 'stream']);

test('L10: a custom ConversationStore that ignores the key keeps working', function (): void {
    $user = (object) ['id' => 1];

    $store = new class extends DatabaseConversationStore
    {
        public array $seen = [];

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
        {
            $this->seen[] = $response->toolResults->map(fn (ToolResult $result) => $result->toArray())->all();

            return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
        }
    };

    app()->instance(ConversationStore::class, $store);

    LkParent::fake([new ToolCall('call_1', 'LkSub', ['task' => 'one']), 'done']);
    LkSub::fake(['r1']);

    (new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user)->prompt('go');

    expect(json_encode($store->seen))->not->toContain('sub_conversation_id');
});

class LkGatedAgentTool extends AgentTool
{
    protected function needsApproval(ToolRequest $request): Approval|bool
    {
        return true;
    }
}

test('L11: an approved sub-agent tool call run on the parent\'s resume is linked (Anthropic over Http::fake)', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...($mode === 'prompt'
            ? [lkAnthropicToolUse('toolu_p1', 'LkSub', ['task' => 'one'])]
            : lkSse(lkAnthropicStream(['type' => 'tool_use', 'id' => 'toolu_p1', 'name' => 'LkSub', 'input' => (object) []], 'tool_use', '{"task":"one"}'))))
        ->push(lkAnthropicText('sub approved hi'))
        ->push(...($mode === 'prompt' ? [lkAnthropicText('final')] : lkSse(lkAnthropicStream(['type' => 'text', 'text' => ''], 'end_turn', 'final')))),
    ]);

    $make = fn () => (new LkParent(fn () => [new LkGatedAgentTool((new LkSub)->forUser($user))]));

    $paused = lkRun($make()->forUser($user), $mode, 'go');

    expect(lkParentToolCalls($paused->conversationId)[0])->toHaveKey('approval_reason')->not->toHaveKey('sub_conversation_id');

    $parent = $make()->continue($paused->conversationId, $user);

    $mode === 'prompt'
        ? $parent->prompt(Decisions::from(['toolu_p1' => true]))
        : iterator_to_array($parent->stream(Decisions::from(['toolu_p1' => true])), false);

    $calls = lkParentToolCalls($paused->conversationId);

    dump("L11 {$mode}: ".json_encode($calls));

    expect($calls[0])->toMatchArray(['id' => 'toolu_p1', 'result' => 'sub approved hi', 'sub_conversation_id' => lkConversationForTask('one')])
        ->and(lkConversationForTask('one'))->not->toBeNull();
})->with(['prompt', 'stream']);

test('L12: a parent that dies after the delegation keeps the link on its failed row', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(lkAnthropicToolUse('toolu_p1', 'LkSub', ['task' => 'one']))
        ->push(lkAnthropicText('sub says hi'))
        ->pushStatus(500)->pushStatus(500)->pushStatus(500),
    ]);

    $parent = (new LkParent(fn () => [(new LkSub)->forUser($user)]))->forUser($user);

    expect(fn () => $parent->prompt('go'))->toThrow(RequestException::class);

    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->where('status', 'failed')->first();

    expect(json_decode($row->steps, true)[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_p1', 'result' => 'sub says hi', 'sub_conversation_id' => lkConversationForTask('one')]);
});
