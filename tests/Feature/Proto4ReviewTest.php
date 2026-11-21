<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

/*
 * Adversarial review of proto4 (per-delegation sub-conversations).
 */

#[Provider('anthropic')]
class RvSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Sub-agent.';
    }
}

class RvPlainSub implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Plain sub-agent.';
    }
}

/** A sub-agent that asks to always be remembered, with no participant (a documented-looking override of the trait). */
class RvAlwaysRememberedSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Always remembered.';
    }

    public function hasConversationParticipant(): bool
    {
        return true;
    }
}

#[Provider('anthropic')]
#[MaxSteps(6)]
class RvParent implements Agent, Conversational, HasTools
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

#[MaxSteps(6)]
class RvPlainParent implements Agent, HasTools
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

/** An agent that delegates to itself (the same instance). */
#[MaxSteps(6)]
class RvSelf implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Delegates to itself.';
    }

    public function tools(): iterable
    {
        return [$this];
    }
}

class RvSecondTool extends AgentTool
{
    public function name(): string
    {
        return 'Second';
    }
}

function rvRun(Agent $agent, string $mode, mixed $input): mixed
{
    if ($mode === 'prompt') {
        return $agent->prompt($input);
    }

    $stream = $agent->stream($input);

    foreach ($stream as $event) {
        //
    }

    return $stream;
}

function rvToolCalls(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)->where('role', 'assistant')->orderBy('id')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->flatMap(fn (array $step) => $step['tool_calls']))
        ->values()->all();
}

function rvConversationForTask(string $task): ?string
{
    return DB::table('agent_conversation_messages')->where('role', 'user')->where('content', $task)->value('conversation_id');
}

function rvAnthropicToolUses(string $msgId, array $uses): array
{
    return [
        'id' => 'msg_'.$msgId, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => array_map(fn (array $use) => ['type' => 'tool_use', 'id' => $use[0], 'name' => $use[1], 'input' => (object) ($use[2] ?? [])], $uses),
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function rvAnthropicText(string $text): array
{
    return [
        'id' => 'msg_'.md5($text), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function rvAnthropicSse(array $block, string $stopReason, string $delta): array
{
    $deltaEvent = $block['type'] === 'text'
        ? ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]]
        : ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $delta]];

    return [implode("\n\n", array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event), [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.md5(json_encode($block)), 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => $block],
        $deltaEvent,
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ]))."\n\n", 200, ['Content-Type' => 'text/event-stream']];
}

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');
    // The review was written against the full spec, where delegations of a remembered parent are stored...
    Config::set('ai.conversations.remember_delegations', true);
    ApprovableNumberGenerator::$invocations = 0;
    ApprovableNumberGenerator::$approvalChecks = 0;
});

test('RV1: a parent stored only because it paused (no participant, no conversation) links the delegation it ran before pausing', function (string $kind): void {
    $sub = $kind === 'trait' ? RvSub::class : RvPlainSub::class;

    // Step 1 delegates, step 2 asks for an approval, so the parent turn is stored (ownerless) on the pause...
    RvParent::fake([
        new ToolCall('call_1', class_basename($sub), ['task' => 'one']),
        new ToolCall('call_2', 'ApprovableNumberGenerator', []),
    ]);
    $sub::fake(['r1']);

    $response = (new RvParent(fn () => [new $sub, new ApprovableNumberGenerator]))->prompt('go');

    expect($response->conversationId)->not->toBeNull();

    $calls = rvToolCalls($response->conversationId);

    dump('RV1 '.$kind.': conversations='.DB::table('agent_conversations')->count().' calls='.json_encode($calls));

    // Documented limitation (ADR 0003): whether a run is remembered is decided before it runs, so a parent stored
    // only because it paused does not save the delegations it made before the pause...
    expect($calls[0])->not->toHaveKey('sub_conversation_id')
        ->and(rvConversationForTask('one'))->toBeNull()
        ->and(DB::table('agent_conversations')->count())->toBe(1);
})->with(['trait', 'plain']);

test('RV2: the same sub-agent instance behind two tools, called in one step, gets two conversations and two links (Anthropic over Http::fake)', function (): void {
    $user = (object) ['id' => 1];
    $sub = new RvSub;

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(rvAnthropicToolUses('p1', [['toolu_1', 'RvSub', ['task' => 'one']], ['toolu_2', 'Second', ['task' => 'two']]]))
        ->push(rvAnthropicText('r1'))
        ->push(rvAnthropicText('r2'))
        ->push(rvAnthropicText('done')),
    ]);

    $response = (new RvParent(fn () => [$sub, new RvSecondTool($sub)]))->forUser($user)->prompt('go');

    $calls = rvToolCalls($response->conversationId);

    dump('RV2: '.json_encode($calls));

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['sub_conversation_id'])->toBe(rvConversationForTask('one'))
        ->and($calls[1]['sub_conversation_id'])->toBe(rvConversationForTask('two'))
        ->and($calls[0]['sub_conversation_id'])->not->toBe($calls[1]['sub_conversation_id'])
        ->and($sub->currentConversation())->toBeNull();
});

test('RV3: a parent step that delegates and asks for an approval in parallel keeps the link across the resume (Anthropic over Http::fake)', function (): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(rvAnthropicToolUses('p1', [['toolu_d', 'RvSub', ['task' => 'one']], ['toolu_a', 'ApprovableNumberGenerator']]))
        ->push(rvAnthropicText('sub says hi'))
        ->push(rvAnthropicText('final')),
    ]);

    $make = fn () => new RvParent(fn () => [new RvSub, new ApprovableNumberGenerator]);

    $paused = $make()->forUser($user)->prompt('go');

    $before = rvToolCalls($paused->conversationId);

    $make()->continue($paused->conversationId, $user)->prompt(Decisions::from(['toolu_a' => true]));

    $after = rvToolCalls($paused->conversationId);

    dump('RV3 before='.json_encode($before).' after='.json_encode($after));

    $subId = rvConversationForTask('one');

    expect($subId)->not->toBeNull()
        ->and(collect($before)->firstWhere('id', 'toolu_d')['sub_conversation_id'] ?? null)->toBe($subId)
        ->and(collect($after)->firstWhere('id', 'toolu_d')['sub_conversation_id'] ?? null)->toBe($subId)
        ->and(collect($after)->firstWhere('id', 'toolu_a')['result'] ?? null)->toBe('72019');
});

test('RV4: a sub-agent overriding hasConversationParticipant() to always be remembered is still remembered (1.x behaviour)', function (string $mode, bool $rememberDelegations): void {
    Config::set('ai.conversations.remember_delegations', $rememberDelegations);

    RvPlainParent::fake([
        new ToolCall('call_1', 'RvAlwaysRememberedSub', ['task' => 'one']),
        'done',
    ]);
    RvAlwaysRememberedSub::fake(['r1']);

    rvRun(new RvPlainParent(fn () => [new RvAlwaysRememberedSub]), $mode, 'go');

    dump("RV4 {$mode}: conversations=".DB::table('agent_conversations')->count());

    expect(rvConversationForTask('one'))->not->toBeNull()
        ->and(DB::table('agent_conversations')->value('participant_id'))->toBeNull();
})->with(['prompt', 'stream'])->with(['switch on' => true, 'switch off' => false]);

test('RV5: an agent delegating to its own instance keeps its own turn in its own conversation', function (string $mode): void {
    $user = (object) ['id' => 1];

    RvSelf::fake([
        new ToolCall('call_1', 'RvSelf', ['task' => 'nested task']),
        'nested answer',
        'top answer',
    ]);

    $agent = (new RvSelf)->forUser($user);

    $response = rvRun($agent, $mode, 'top prompt');

    $top = rvConversationForTask('top prompt');
    $nested = rvConversationForTask('nested task');

    dump("RV5 {$mode}: top={$top} nested={$nested} response={$response->conversationId} instance=".$agent->currentConversation());

    expect($top)->toBe($response->conversationId)
        ->and($nested)->not->toBe($top)
        ->and(rvToolCalls($top)[0]['sub_conversation_id'] ?? null)->toBe($nested);
})->with(['prompt', 'stream']);

test('RV6: a parent stream kept alive after the consumer stops mid-delegation leaves no static reference to the sub-agent', function (): void {
    $user = (object) ['id' => 1];

    // Over Http::fake, not agent fakes: agent fakes record every prompt, and with it the sub-agent...
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...rvAnthropicSse(['type' => 'tool_use', 'id' => 'toolu_p1', 'name' => 'RvSub', 'input' => (object) []], 'tool_use', '{"task":"one"}'))
        ->push(...rvAnthropicSse(['type' => 'text', 'text' => ''], 'end_turn', 'r1'))
        ->push(...rvAnthropicSse(['type' => 'text', 'text' => ''], 'end_turn', 'done')),
    ]);

    $sub = new RvSub;
    $subRef = WeakReference::create($sub);

    $stream = (new RvParent(fn () => [$sub]))->forUser($user)->stream('go');

    unset($sub);

    $kept = $stream->getIterator();
    $midDelegation = false;

    foreach ($kept as $event) {
        // A preliminary result of the sub-agent tool call: the delegation is running, and the parent is suspended inside it...
        if ($event instanceof ToolResult && $event->preliminary) {
            $midDelegation = true;

            break;
        }
    }

    $slot = (new ReflectionProperty(AgentTool::class, 'delegation'))->getValue();
    $marks = (new ReflectionProperty(RememberConversation::class, 'remembered'))->getValue();

    unset($kept, $stream, $event);

    $marksAfterRelease = count((new ReflectionProperty(RememberConversation::class, 'remembered'))->getValue());

    gc_collect_cycles();

    dump('RV6: mid-delegation='.var_export($midDelegation, true).' slot while retained='.json_encode($slot).' marks while retained='.json_encode($marks).' marks after release (no gc)='.$marksAfterRelease.' sub-agent alive after release='.var_export($subRef->get() !== null, true));

    // The delegation slot is scoped to synchronous execution, and the marks hold only invocation IDs...
    expect($midDelegation)->toBeTrue()
        ->and($slot)->toBeNull()
        ->and($marks)->toHaveCount(2)
        ->and(array_values(array_unique($marks)))->toBe([true])
        ->and($marksAfterRelease)->toBe(0)
        ->and($subRef->get())->toBeNull();
});

#[Provider(['primary', 'backup'])]
#[MaxSteps(6)]
class RvFailoverParent extends RvParent
{
    //
}

function rvGroqToolCall(string $id, string $name, array $arguments): array
{
    return [
        'id' => 'chatcmpl-'.$id, 'object' => 'chat.completion', 'created' => 1, 'model' => 'test',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]],
        ]], 'finish_reason' => 'tool_calls']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
    ];
}

function rvGroqText(string $text): array
{
    return [
        'id' => 'chatcmpl-t', 'object' => 'chat.completion', 'created' => 1, 'model' => 'test',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
    ];
}

test('RV7: a parent that fails over after delegating leaves no orphaned sub-conversation', function (bool $rememberDelegations): void {
    Config::set('ai.conversations.remember_delegations', $rememberDelegations);

    $user = (object) ['id' => 1];

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(rvGroqToolCall('call_a', 'RvPlainSub', ['task' => 'attempt one']), 200)
        ->push(status: 429)
        ->push(rvGroqToolCall('call_b', 'RvPlainSub', ['task' => 'attempt two']), 200)
        ->push(rvGroqText('done'), 200);

    RvPlainSub::fake(['r1', 'r2']);

    $response = (new RvFailoverParent(fn () => [new RvPlainSub]))->forUser($user)->prompt('go');

    $linked = array_column(rvToolCalls($response->conversationId), 'sub_conversation_id');
    $ownerless = DB::table('agent_conversations')->whereNull('participant_id')->orderBy('id')->pluck('id')->all();

    dump('RV7: text='.$response->text.' linked='.json_encode($linked).' ownerless='.json_encode($ownerless));

    if (! $rememberDelegations) {
        expect($ownerless)->toBe([])->and($linked)->toBe([]);

        return;
    }

    // Documented limitation (ADR 0003): the failed attempt's sub-conversation stays, unlinked; the store has no delete...
    expect($ownerless)->toHaveCount(2)
        ->and($linked)->toBe([$ownerless[1]]);
})->with(['switch on' => true, 'switch off' => false]);
