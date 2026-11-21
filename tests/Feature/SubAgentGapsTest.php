<?php

use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
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
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;

/*
 * proto4 gaps: failover, queued and broadcast parents, static state hygiene, approvals.
 * Every agent runs over Http::fake (no agent fakes), except the title agent, which is faked so it never consumes a sequence.
 * The assertions encode the proto4 behaviour; on plain 1.x the failures show what the diff changes.
 */

#[Provider('anthropic')]
class GpSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Sub-agent.';
    }
}

#[Provider(['primary', 'backup'])]
class GpFailoverSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Fails over.';
    }
}

#[Provider(['primary', 'backup'])]
#[MaxSteps(4)]
class GpFailoverToolSub implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Uses a tool, fails over mid-run.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}

#[Provider('anthropic')]
class GpApprovingSub implements Agent, Conversational, HasTools
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
class GpParent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /** Plain array of tools so the agent survives queue serialization. */
    public function __construct(public array $subTools = [])
    {
        //
    }

    public function instructions(): string
    {
        return 'Delegate.';
    }

    public function tools(): iterable
    {
        return $this->subTools;
    }
}

#[Provider(['primary', 'backup'])]
#[MaxSteps(6)]
class GpFailoverParent extends GpParent
{
    //
}

// ---------------------------------------------------------------- helpers

function gpIsProto(): bool
{
    return property_exists(AgentTool::class, 'delegation');
}

function gpVariant(): string
{
    return gpIsProto() ? 'final-holder' : '1.x';
}

/** 1 while the scoped delegation slot is set (it must only be set while a sub-agent runs synchronously), else 0. */
function gpDelegations(): ?int
{
    return gpIsProto() ? (int) ((new ReflectionProperty(AgentTool::class, 'delegation'))->getValue() !== null) : null;
}

/** The number of runs marked as remembered with their delegations. */
function gpRemembering(): ?int
{
    return gpIsProto() ? count((new ReflectionProperty(RememberConversation::class, 'remembered'))->getValue()) : null;
}

function gpText(string $text): array
{
    return [[
        'id' => 'msg_'.md5($text.microtime()), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]];
}

function gpToolUse(string $id, string $name, array $input = []): array
{
    return [[
        'id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) $input]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]];
}

function gpSseBody(array $block, string $stopReason, string $delta): string
{
    $deltaEvent = $block['type'] === 'text'
        ? ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]]
        : ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $delta]];

    return implode("\n\n", array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event), [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.md5(json_encode($block).$delta), 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => $block],
        $deltaEvent,
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ]))."\n\n";
}

/** A text reply, as JSON (prompt) or SSE (stream). */
function gpReplyText(string $mode, string $text): array
{
    return $mode === 'prompt'
        ? gpText($text)
        : [gpSseBody(['type' => 'text', 'text' => ''], 'end_turn', $text), 200, ['Content-Type' => 'text/event-stream']];
}

/** A tool_use reply, as JSON (prompt) or SSE (stream). */
function gpReplyTool(string $mode, string $id, string $name, array $input = []): array
{
    return $mode === 'prompt'
        ? gpToolUse($id, $name, $input)
        : [gpSseBody(['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []], 'tool_use', json_encode((object) $input)), 200, ['Content-Type' => 'text/event-stream']];
}

function gpRun(Agent $agent, string $mode, mixed $input): mixed
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

function gpParentCalls(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)->where('role', 'assistant')->orderBy('id')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->flatMap(fn (array $step) => $step['tool_calls']))
        ->values()->all();
}

function gpLinks(string $conversationId): array
{
    return array_map(fn (array $call) => $call['sub_conversation_id'] ?? null, gpParentCalls($conversationId));
}

function gpOwnerless(): array
{
    return DB::table('agent_conversations')->whereNull('participant_id')->orderBy('id')->pluck('id')->all();
}

function gpConversationsOf(int $id): array
{
    return DB::table('agent_conversations')->where('participant_id', $id)->orderBy('id')->pluck('id')->all();
}

function gpRows(string $conversationId): array
{
    return DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->orderBy('id')
        ->get()->map(fn ($row) => $row->role === 'user' ? 'user:'.$row->status.':'.$row->content : $row->role.':'.$row->status.':'.$row->content)->all();
}

/** Conversations holding a user row with the given content. */
function gpConversationsForTask(string $task): array
{
    return DB::table('agent_conversation_messages')->where('role', 'user')->where('content', $task)->orderBy('id')->pluck('conversation_id')->all();
}

function gpTitleCalls(): object
{
    $counter = (object) ['count' => 0];

    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use ($counter): void {
        if ($event->prompt->agent instanceof ConversationTitleAgent) {
            $counter->count++;
        }
    });

    return $counter;
}

function gpFailovers(): object
{
    $counter = (object) ['count' => 0];

    Event::listen(AgentFailedOver::class, function () use ($counter): void {
        $counter->count++;
    });

    return $counter;
}

/** The sub-agent instance for a kind: given nothing, or forUser($other). */
function gpSub(string $class, string $kind): Agent
{
    return $kind === 'forUser' ? (new $class)->forUser((object) ['id' => 2]) : new $class;
}

/** The sub-conversations a kind stores into: ownerless (given nothing) or owned by user 2. */
function gpSubConversations(string $kind): array
{
    return $kind === 'forUser' ? gpConversationsOf(2) : gpOwnerless();
}

function gpState(): string
{
    return sprintf('delegations=%s remembering=%s', var_export(gpDelegations(), true), var_export(gpRemembering(), true));
}

function gpAssertClean(): void
{
    if (gpIsProto()) {
        // No delegation slot, and no mark left behind, without waiting for the cycle collector...
        expect(gpDelegations())->toBe(0)
            ->and(gpRemembering())->toBe(0);
    }
}

beforeEach(function (): void {
    Config::set('ai.providers.anthropic.key', 'test-key');
    Config::set('ai.providers.primary', ['driver' => 'anthropic', 'key' => 'test-key', 'url' => 'https://primary.test/v1']);
    Config::set('ai.providers.backup', ['driver' => 'anthropic', 'key' => 'test-key', 'url' => 'https://backup.test/v1']);
    Config::set('ai.conversations.generate_title', true);
    // The gaps were probed against the full spec, where delegations of a remembered parent are stored...
    Config::set('ai.conversations.remember_delegations', true);
    ConversationTitleAgent::fake(fn () => 'A title');
    ApprovableNumberGenerator::$invocations = 0;
    $GLOBALS['gpResults'] = [];
    Http::preventStrayRequests();
});

// ---------------------------------------------------------------- 1. failover

test('G1a: the parent fails over before any delegation (503 from primary, success from backup)', function (string $mode, string $kind): void {
    $titles = gpTitleCalls();
    $failovers = gpFailovers();
    $user = (object) ['id' => 1];

    Http::fake([
        'primary.test/*' => Http::sequence()->pushStatus(503),
        'backup.test/*' => Http::sequence()
            ->push(...gpReplyTool($mode, 'toolu_p1', 'GpSub', ['task' => 'one']))
            ->push(...gpReplyTool($mode, 'toolu_p2', 'GpSub', ['task' => 'two']))
            ->push(...gpReplyText($mode, 'done')),
        'api.anthropic.com/*' => Http::sequence()->push(...gpReplyText($mode, 'r1'))->push(...gpReplyText($mode, 'r2')),
    ]);

    $sub = gpSub(GpSub::class, $kind);
    $response = gpRun((new GpFailoverParent([$sub]))->forUser($user), $mode, 'go');

    $subs = gpSubConversations($kind);
    $links = gpLinks($response->conversationId);

    dump(sprintf('G1a %s %s %s: sub instance conversation=%s', gpVariant(), $mode, $kind, var_export($sub->currentConversation(), true)));
    dump(sprintf('G1a %s %s %s: failovers=%d parent convs=%s parent rows=%s sub convs=%d rows=%s links=%s titles=%d %s', gpVariant(), $mode, $kind,
        $failovers->count, json_encode(gpConversationsOf(1)), json_encode(gpRows($response->conversationId)), count($subs),
        json_encode(array_map('gpRows', $subs)), json_encode($links), $titles->count, gpState()));

    expect($failovers->count)->toBe(1)
        ->and($response->text)->toBe('done')
        ->and(gpConversationsOf(1))->toBe([$response->conversationId])
        ->and(gpRows($response->conversationId))->toHaveCount(2)
        ->and($subs)->toHaveCount(2)
        ->and($links)->toBe($subs)
        ->and(gpRows($subs[0]))->toBe(['user:completed:one', 'assistant:completed:r1'])
        ->and(gpRows($subs[1]))->toBe(['user:completed:two', 'assistant:completed:r2'])
        ->and($titles->count)->toBe(1)
        ->and($sub->currentConversation())->toBeNull();

    gpAssertClean();
})->with(['prompt', 'stream'])->with(['nothing', 'forUser']);

test('G1b: the parent fails over AFTER a delegation already ran on the primary (documents the rows the failed attempt leaves)', function (string $kind): void {
    $titles = gpTitleCalls();
    $failovers = gpFailovers();
    $user = (object) ['id' => 1];

    Http::fake([
        'primary.test/*' => Http::sequence()->push(...gpToolUse('toolu_p1', 'GpSub', ['task' => 'one']))->pushStatus(503),
        'backup.test/*' => Http::sequence()->push(...gpToolUse('toolu_p1', 'GpSub', ['task' => 'one']))->push(...gpText('done')),
        'api.anthropic.com/*' => Http::sequence()->push(...gpText('r1 (attempt 1)'))->push(...gpText('r1 (attempt 2)')),
    ]);

    $sub = gpSub(GpSub::class, $kind);
    $response = (new GpFailoverParent([$sub]))->forUser($user)->prompt('go');

    dump(sprintf('G1b %s prompt %s: sub instance conversation=%s', gpVariant(), $kind, var_export($sub->currentConversation(), true)));

    $subs = gpSubConversations($kind);
    $links = gpLinks($response->conversationId);
    $orphans = array_values(array_diff($subs, $links));

    dump(sprintf('G1b %s prompt %s: failovers=%d parent rows=%s sub convs=%s links=%s orphans=%s titles=%d %s', gpVariant(), $kind,
        $failovers->count, json_encode(gpRows($response->conversationId)),
        json_encode(array_map('gpRows', $subs)), json_encode($links), json_encode($orphans), $titles->count, gpState()));

    // The parent's failed attempt is not written (a failover retry writes the turn), exactly like a top-level failover in 1.x...
    expect($failovers->count)->toBe(1)
        ->and(gpRows($response->conversationId))->toHaveCount(2)
        ->and(DB::table('agent_conversation_messages')->where('status', 'failed')->count())->toBe(0)
        // ...but the delegation the failed attempt ran is already stored: one orphan sub-conversation, never linked.
        ->and($subs)->toHaveCount(2)
        ->and($links)->toBe([$subs[1]])
        ->and(gpRows($subs[1]))->toBe(['user:completed:one', 'assistant:completed:r1 (attempt 2)'])
        ->and($orphans)->toBe([$subs[0]])
        ->and(gpRows($subs[0]))->toBe(['user:completed:one', 'assistant:completed:r1 (attempt 1)'])
        ->and($titles->count)->toBe(1);

    gpAssertClean();
})->with(['nothing', 'forUser']);

test('G1b-stream: a streamed parent whose primary dies after a delegation does NOT fail over (it has yielded)', function (string $kind): void {
    $failovers = gpFailovers();
    $user = (object) ['id' => 1];

    Http::fake([
        'primary.test/*' => Http::sequence()->push(...gpReplyTool('stream', 'toolu_p1', 'GpSub', ['task' => 'one']))->pushStatus(503),
        'backup.test/*' => Http::sequence(),
        'api.anthropic.com/*' => Http::sequence()->push(...gpReplyText('stream', 'r1')),
    ]);

    $error = null;

    try {
        gpRun((new GpFailoverParent([gpSub(GpSub::class, $kind)]))->forUser($user), 'stream', 'go');
    } catch (Throwable $e) {
        $error = $e::class;
    }

    $subs = gpSubConversations($kind);
    $parent = gpConversationsOf(1);

    dump(sprintf('G1b-stream %s %s: error=%s failovers=%d parent convs=%s parent rows=%s parent links=%s sub convs=%s=%s %s', gpVariant(), $kind, $error, $failovers->count,
        json_encode($parent), json_encode(array_map('gpRows', $parent)), json_encode(array_map('gpLinks', $parent)), json_encode($subs), json_encode(array_map('gpRows', $subs)), gpState()));

    expect($error)->not->toBeNull()
        ->and($failovers->count)->toBe(0)
        ->and($subs)->toHaveCount(1);

    gpAssertClean();
})->with(['nothing', 'forUser']);

test('G1c: the sub-agent fails over on every delegation (503 from primary, success from backup)', function (string $mode, string $kind): void {
    $titles = gpTitleCalls();
    $failovers = gpFailovers();
    $user = (object) ['id' => 1];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(...gpReplyTool($mode, 'toolu_p1', 'GpFailoverSub', ['task' => 'one']))
            ->push(...gpReplyTool($mode, 'toolu_p2', 'GpFailoverSub', ['task' => 'two']))
            ->push(...gpReplyText($mode, 'done')),
        'primary.test/*' => Http::sequence()->pushStatus(503)->pushStatus(503),
        'backup.test/*' => Http::sequence()->push(...gpReplyText($mode, 'r1'))->push(...gpReplyText($mode, 'r2')),
    ]);

    $response = gpRun((new GpParent([gpSub(GpFailoverSub::class, $kind)]))->forUser($user), $mode, 'go');

    $subs = gpSubConversations($kind);
    $links = gpLinks($response->conversationId);

    dump(sprintf('G1c %s %s %s: failovers=%d sub convs=%s links=%s results=%s titles=%d %s', gpVariant(), $mode, $kind, $failovers->count,
        json_encode(array_map('gpRows', $subs)), json_encode($links), json_encode(array_column(gpParentCalls($response->conversationId), 'result')), $titles->count, gpState()));

    expect($failovers->count)->toBe(2)
        ->and(array_column(gpParentCalls($response->conversationId), 'result'))->toBe(['r1', 'r2'])
        ->and($subs)->toHaveCount(2)
        ->and($links)->toBe($subs)
        ->and(gpRows($subs[0]))->toBe(['user:completed:one', 'assistant:completed:r1'])
        ->and(gpRows($subs[1]))->toBe(['user:completed:two', 'assistant:completed:r2'])
        ->and(DB::table('agent_conversations')->count())->toBe(3)
        ->and($titles->count)->toBe(1);

    gpAssertClean();
})->with(['prompt', 'stream'])->with(['nothing', 'forUser']);

test('G1d: the sub-agent fails over MID-RUN (after its own tool step on the primary)', function (string $kind): void {
    $failovers = gpFailovers();
    $titles = gpTitleCalls();
    $user = (object) ['id' => 1];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(...gpToolUse('toolu_p1', 'GpFailoverToolSub', ['task' => 'one']))
            ->push(...gpText('done')),
        'primary.test/*' => Http::sequence()->push(...gpToolUse('toolu_s1', 'FixedNumberGenerator'))->pushStatus(503),
        'backup.test/*' => Http::sequence()->push(...gpToolUse('toolu_s1b', 'FixedNumberGenerator'))->push(...gpText('the number')),
    ]);

    $response = (new GpParent([gpSub(GpFailoverToolSub::class, $kind)]))->forUser($user)->prompt('go');

    $subs = gpSubConversations($kind);
    $links = gpLinks($response->conversationId);

    dump(sprintf('G1d %s prompt %s: failovers=%d sub convs=%s links=%s titles=%d %s', gpVariant(), $kind, $failovers->count,
        json_encode(array_map('gpRows', $subs)), json_encode($links), $titles->count, gpState()));

    expect($failovers->count)->toBe(1)
        ->and($subs)->toHaveCount(1)
        ->and($links)->toBe($subs)
        ->and(gpRows($subs[0]))->toBe(['user:completed:one', 'assistant:completed:the number'])
        ->and(DB::table('agent_conversation_messages')->where('status', 'failed')->count())->toBe(0)
        ->and($titles->count)->toBe(1);

    gpAssertClean();
})->with(['nothing', 'forUser']);

// ---------------------------------------------------------------- 2. queued parent

/** Two delegations per run, for the given number of back-to-back runs, all on api.anthropic.com. */
function gpTwoDelegationScript(string $mode, int $runs, string $subName = 'GpSub'): void
{
    $sequence = Http::sequence();

    foreach (range(1, $runs) as $run) {
        $sequence->push(...gpReplyTool($mode, "toolu_r{$run}_1", $subName, ['task' => "run{$run} one"]))
            ->push(...gpReplyText($mode, "run{$run} r1"))
            ->push(...gpReplyTool($mode, "toolu_r{$run}_2", $subName, ['task' => "run{$run} two"]))
            ->push(...gpReplyText($mode, "run{$run} r2"))
            ->push(...gpReplyText($mode, "run{$run} done"));
    }

    Http::fake(['api.anthropic.com/*' => $sequence]);
}

function gpAssertRunStored(int $run, string $parentConversationId, string $kind): array
{
    $one = gpConversationsForTask("run{$run} one");
    $two = gpConversationsForTask("run{$run} two");
    $links = gpLinks($parentConversationId);

    return [
        'line' => sprintf('run%d: parent rows=%d links=%s task convs=%s/%s', $run, count(gpRows($parentConversationId)), json_encode($links), json_encode($one), json_encode($two)),
        'check' => function () use ($one, $two, $links, $parentConversationId, $run): void {
            expect(gpRows($parentConversationId))->toHaveCount(2)
                ->and($one)->toHaveCount(1)
                ->and($two)->toHaveCount(1)
                ->and($one[0])->not->toBe($two[0])
                ->and($links)->toBe([$one[0], $two[0]])
                ->and(gpRows($one[0]))->toBe(["user:completed:run{$run} one", "assistant:completed:run{$run} r1"]);
        },
    ];
}

test('G2: two queued parents run back to back on the sync queue', function (string $kind, string $how): void {
    $titles = gpTitleCalls();
    $user = (object) ['id' => 1];

    gpTwoDelegationScript('prompt', 2);

    $states = [];

    foreach ([1, 2] as $run) {
        $agent = (new GpParent([gpSub(GpSub::class, $kind)]))->forUser($user);

        if ($how === 'queue()') {
            $agent->queue('go')->then(function ($response): void {
                $GLOBALS['gpResults'][] = $response->conversationId;
            });
        } else {
            $job = (new InvokeAgent($agent, 'go'))->then(function ($response): void {
                $GLOBALS['gpResults'][] = $response->conversationId;
            });

            // Round-trip through serialization as a real worker would...
            unserialize(serialize($job))->handle();
        }

        $states[$run] = ['delegations' => gpDelegations(), 'remembering (no gc)' => gpRemembering()];
        gc_collect_cycles();
        $states[$run]['remembering (after gc)'] = gpRemembering();
    }

    $runs = array_map(fn ($run) => gpAssertRunStored($run, $GLOBALS['gpResults'][$run - 1], $kind), [1, 2]);

    dump(sprintf('G2 %s %s %s: parents=%s subs=%d titles=%d %s | states=%s', gpVariant(), $how, $kind, json_encode($GLOBALS['gpResults']),
        count(gpSubConversations($kind)), $titles->count, implode(' | ', array_column($runs, 'line')), json_encode($states)));

    expect($GLOBALS['gpResults'])->toHaveCount(2)
        ->and(gpConversationsOf(1))->toBe($GLOBALS['gpResults'])
        ->and(gpSubConversations($kind))->toHaveCount(4)
        ->and($titles->count)->toBe(2);

    foreach ($runs as $run) {
        ($run['check'])();
    }

    if (gpIsProto()) {
        expect(array_column($states, 'delegations'))->toBe([0, 0])
            ->and(array_column($states, 'remembering (no gc)'))->toBe([0, 0])
            ->and(array_column($states, 'remembering (after gc)'))->toBe([0, 0]);
    }
})->with(['nothing', 'forUser'])->with(['queue()', 'InvokeAgent::handle() after serialize']);

// ---------------------------------------------------------------- 3. broadcast parent

test('G3: two broadcast parents back to back', function (string $how, string $kind): void {
    Event::fake([AnonymousEvent::class]);

    $titles = gpTitleCalls();
    $user = (object) ['id' => 1];

    gpTwoDelegationScript('stream', 2);

    $states = [];

    foreach ([1, 2] as $run) {
        $agent = (new GpParent([gpSub(GpSub::class, $kind)]))->forUser($user);
        $channel = new Channel('run'.$run);

        if ($how === 'broadcastOnQueue()') {
            $agent->broadcastOnQueue('go', $channel)->then(function ($response): void {
                $GLOBALS['gpResults'][] = $response->conversationId;
            });
        } else {
            $stream = $how === 'broadcastNow()' ? $agent->broadcastNow('go', $channel) : $agent->broadcast('go', $channel);

            foreach ($stream as $event) {
                //
            }

            $GLOBALS['gpResults'][] = $stream->conversationId;

            unset($stream, $event);
        }

        $states[$run] = ['delegations' => gpDelegations(), 'remembering (no gc)' => gpRemembering()];
        gc_collect_cycles();
        $states[$run]['remembering (after gc)'] = gpRemembering();
    }

    $runs = array_map(fn ($run) => gpAssertRunStored($run, $GLOBALS['gpResults'][$run - 1], $kind), [1, 2]);

    $broadcasts = Event::dispatched(AnonymousEvent::class)->count();
    $subToolResultBroadcasts = Event::dispatched(AnonymousEvent::class)->filter(fn ($e) => str_contains(json_encode($e[0]->broadcastWith()), 'sub_conversation'))->count();

    dump(sprintf('G3 %s %s %s: parents=%s subs=%d broadcasts=%d broadcasts carrying sub_conversation=%d titles=%d %s | states=%s', gpVariant(), $how, $kind,
        json_encode($GLOBALS['gpResults']), count(gpSubConversations($kind)), $broadcasts, $subToolResultBroadcasts, $titles->count,
        implode(' | ', array_column($runs, 'line')), json_encode($states)));

    expect($GLOBALS['gpResults'])->toHaveCount(2)
        ->and(gpConversationsOf(1))->toBe($GLOBALS['gpResults'])
        ->and(gpSubConversations($kind))->toHaveCount(4)
        ->and($broadcasts)->toBeGreaterThan(0)
        ->and($subToolResultBroadcasts)->toBe(0)
        ->and($titles->count)->toBe(2);

    foreach ($runs as $run) {
        ($run['check'])();
    }

    if (gpIsProto()) {
        expect(array_column($states, 'delegations'))->toBe([0, 0])
            ->and(array_column($states, 'remembering (no gc)'))->toBe([0, 0])
            ->and(array_column($states, 'remembering (after gc)'))->toBe([0, 0]);
    }
})->with(['broadcastNow()', 'broadcast()', 'broadcastOnQueue()'])->with(['nothing', 'forUser']);

// ---------------------------------------------------------------- 4. static state hygiene

test('G4a: WeakMap marks of a finished direct run, with and without gc_collect_cycles()', function (string $mode): void {
    $user = (object) ['id' => 1];

    gc_collect_cycles();
    $before = gpRemembering();

    gpTwoDelegationScript($mode, 1);

    $response = gpRun((new GpParent([new GpSub]))->forUser($user), $mode, 'go');

    $whileHeld = gpRemembering();
    $delegations = gpDelegations();

    unset($response);
    $released = gpRemembering();

    gc_collect_cycles();
    $collected = gpRemembering();

    dump(sprintf('G4a %s %s: delegations=%s remembering before=%s while response held=%s after unset (no gc)=%s after gc=%s', gpVariant(), $mode,
        var_export($delegations, true), var_export($before, true), var_export($whileHeld, true), var_export($released, true), var_export($collected, true)));

    gpAssertClean();

    if (gpIsProto()) {
        // The marks go when the run ends, not when its response is released or collected...
        expect($whileHeld)->toBe($before)
            ->and($released)->toBe($before)
            ->and($collected)->toBe($before);
    }
})->with(['prompt', 'stream']);

test('G4b: a listener that throws after the delegation escapes the parent', function (string $mode): void {
    $user = (object) ['id' => 1];

    Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
        if ($event->tool instanceof AgentTool) {
            throw new RuntimeException('listener boom');
        }
    });

    gpTwoDelegationScript($mode, 1);

    $error = null;

    try {
        gpRun((new GpParent([new GpSub]))->forUser($user), $mode, 'go');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $noGc = gpRemembering();
    gc_collect_cycles();

    dump(sprintf('G4b %s %s: error=%s delegations=%s remembering no gc=%s after gc=%s ownerless=%s parent convs=%s', gpVariant(), $mode, $error,
        var_export(gpDelegations(), true), var_export($noGc, true), var_export(gpRemembering(), true), json_encode(array_map('gpRows', gpOwnerless())), json_encode(array_map('gpRows', gpConversationsOf(1)))));
    dump(sprintf('G4b %s %s: ownerless=%s parent stored tool calls=%s', gpVariant(), $mode, json_encode(gpOwnerless()), json_encode(array_map('gpParentCalls', gpConversationsOf(1)))));

    expect($error)->toBe('listener boom');

    gpAssertClean();
})->with(['prompt', 'stream']);

test('G4c: the sub-agent throws mid-delegation (non-failoverable 500)', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...gpReplyTool($mode, 'toolu_p1', 'GpSub', ['task' => 'one']))
        ->pushStatus(500)
        ->push(...gpReplyText($mode, 'done')),
    ]);

    $response = gpRun((new GpParent([new GpSub]))->forUser($user), $mode, 'go');

    $calls = gpParentCalls($response->conversationId);

    dump(sprintf('G4c %s %s: call=%s ownerless=%d %s', gpVariant(), $mode, json_encode($calls[0]), count(gpOwnerless()), gpState()));

    expect($calls[0]['failed'] ?? false)->toBeTrue()
        ->and(gpOwnerless())->toBe([]);

    gpAssertClean();
})->with(['prompt', 'stream']);

test('G4d: a stream consumer that throws in the middle of a delegation', function (): void {
    $user = (object) ['id' => 1];

    gpTwoDelegationScript('stream', 1);

    $stream = (new GpParent([new GpSub]))->forUser($user)->stream('go');

    $error = null;

    try {
        foreach ($stream as $event) {
            if ($event instanceof ToolResultEvent && $event->preliminary) {
                $GLOBALS['gpResults']['during'] = gpDelegations();

                throw new RuntimeException('consumer boom');
            }
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    $whileHeld = gpDelegations();

    unset($stream, $event);
    $released = gpDelegations();
    $rememberingNoGc = gpRemembering();

    gc_collect_cycles();

    dump(sprintf('G4d %s: delegations inside the loop=%s', gpVariant(), var_export($GLOBALS['gpResults']['during'] ?? null, true)));
    dump(sprintf('G4d %s: error=%s delegations while stream held=%s after unset (no gc)=%s after gc=%s remembering no gc=%s after gc=%s conversations=%d',
        gpVariant(), $error, var_export($whileHeld, true), var_export($released, true), var_export(gpDelegations(), true),
        var_export($rememberingNoGc, true), var_export(gpRemembering(), true), DB::table('agent_conversations')->count()));

    expect($error)->toBe('consumer boom');

    if (gpIsProto()) {
        expect($GLOBALS['gpResults']['during'])->toBe(0)
            ->and($rememberingNoGc)->toBe(0);
    }

    gpAssertClean();
});

// ---------------------------------------------------------------- 5. approvals

test('G5a: the parent\'s AgentTool gated with requireApproval(), approved on resume', function (string $mode, string $kind): void {
    $titles = gpTitleCalls();
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...gpReplyTool($mode, 'toolu_p1', 'GpSub', ['task' => 'one']))
        ->push(...gpText('sub approved hi'))
        ->push(...gpReplyText($mode, 'final')),
    ]);

    $make = fn () => new GpParent([(new AgentTool(gpSub(GpSub::class, $kind)))->requireApproval('check')]);

    $paused = gpRun($make()->forUser($user), $mode, 'go');

    $pausedCall = gpParentCalls($paused->conversationId)[0];
    $subsWhilePaused = gpSubConversations($kind);

    gpAssertClean();

    $resumed = gpRun($make()->continue($paused->conversationId, $user), $mode, Decisions::from(['toolu_p1' => true]));

    $calls = gpParentCalls($paused->conversationId);
    $subs = gpSubConversations($kind);

    dump(sprintf('G5a %s %s %s: paused call=%s subs while paused=%d resumed text=%s calls=%s subs=%s titles=%d %s', gpVariant(), $mode, $kind,
        json_encode($pausedCall), count($subsWhilePaused), $resumed->text, json_encode($calls), json_encode(array_map('gpRows', $subs)), $titles->count, gpState()));

    expect($pausedCall)->toHaveKey('approval_reason')->not->toHaveKey('sub_conversation_id')
        ->and($subsWhilePaused)->toBe([])
        ->and($subs)->toHaveCount(1)
        ->and(gpConversationsForTask('one'))->toBe($subs)
        ->and(gpRows($subs[0]))->toBe(['user:completed:one', 'assistant:completed:sub approved hi'])
        ->and($calls)->toHaveCount(1)
        ->and($calls[0])->toMatchArray(['id' => 'toolu_p1', 'result' => 'sub approved hi', 'sub_conversation_id' => $subs[0]])
        ->and($titles->count)->toBe(1);

    gpAssertClean();
})->with(['prompt', 'stream'])->with(['nothing', 'forUser']);

test('G5b: a sub-agent pausing on its own approval, then a second delegation in the same parent run', function (string $mode): void {
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...gpReplyTool($mode, 'toolu_p1', 'GpApprovingSub', ['task' => 'one']))
        ->push(...gpReplyTool($mode, 'toolu_s1', 'ApprovableNumberGenerator'))
        ->push(...gpReplyTool($mode, 'toolu_p2', 'GpApprovingSub', ['task' => 'two']))
        ->push(...gpReplyText($mode, 'two done'))
        ->push(...gpReplyText($mode, 'final'))
        ->push(...gpText('The number is 72019.')),
    ]);

    $response = gpRun((new GpParent([new GpApprovingSub]))->forUser($user), $mode, 'go');

    $ownerless = gpOwnerless();
    $links = gpLinks($response->conversationId);
    $one = gpConversationsForTask('one');
    $two = gpConversationsForTask('two');

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
    $delegation2Messages = $requests[3]['messages'] ?? null;

    $pausedRowsBefore = $one === [] ? [] : gpRows($one[0]);

    dump(sprintf('G5b %s %s: ownerless=%s links=%s results=%s delegation-2 request messages=%s invocations=%d %s', gpVariant(), $mode,
        json_encode(array_map('gpRows', $ownerless)), json_encode($links), json_encode(array_column(gpParentCalls($response->conversationId), 'result')),
        json_encode($delegation2Messages), ApprovableNumberGenerator::$invocations, gpState()));

    expect($ownerless)->toHaveCount(2)
        ->and($one)->toHaveCount(1)
        ->and($two)->toHaveCount(1)
        ->and($links)->toBe([$one[0], $two[0]])
        ->and(DB::table('agent_conversations')->where('id', $one[0])->value('participant_id'))->toBeNull()
        ->and(gpRows($one[0]))->toBe(['user:completed:one', 'assistant:paused:'])
        ->and(gpRows($two[0]))->toBe(['user:completed:two', 'assistant:completed:two done'])
        ->and($delegation2Messages)->toHaveCount(1)
        ->and(ApprovableNumberGenerator::$invocations)->toBe(0);

    gpAssertClean();

    // The paused sub-conversation still resumes on its own...
    $resumed = (new GpApprovingSub)->continue($one[0])->prompt(Decisions::from(['toolu_s1' => true]));

    dump(sprintf('G5b %s %s: resumed=%s rows=%s', gpVariant(), $mode, $resumed->text, json_encode(gpRows($one[0]))));

    expect($resumed->text)->toBe('The number is 72019.')
        ->and(ApprovableNumberGenerator::$invocations)->toBe(1)
        ->and(gpRows($two[0]))->toBe(['user:completed:two', 'assistant:completed:two done']);
})->with(['prompt', 'stream']);

test('G5c: the gated delegation approved through a queued resume (queue(Decisions) on the sync queue)', function (string $kind): void {
    $titles = gpTitleCalls();
    $user = (object) ['id' => 1];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(...gpToolUse('toolu_p1', 'GpSub', ['task' => 'one']))
        ->push(...gpText('sub approved hi'))
        ->push(...gpText('final')),
    ]);

    $make = fn () => new GpParent([(new AgentTool(gpSub(GpSub::class, $kind)))->requireApproval('check')]);

    $paused = $make()->forUser($user)->prompt('go');

    $make()->continue($paused->conversationId, $user)->queue(Decisions::from(['toolu_p1' => true]))->then(function ($response): void {
        $GLOBALS['gpResults'][] = $response->text;
    });

    $calls = gpParentCalls($paused->conversationId);
    $subs = gpSubConversations($kind);

    dump(sprintf('G5c %s %s: resumed=%s calls=%s subs=%s titles=%d %s', gpVariant(), $kind, json_encode($GLOBALS['gpResults']), json_encode($calls),
        json_encode(array_map('gpRows', $subs)), $titles->count, gpState()));

    expect($GLOBALS['gpResults'])->toBe(['final'])
        ->and($subs)->toHaveCount(1)
        ->and($calls)->toHaveCount(1)
        ->and($calls[0])->toMatchArray(['result' => 'sub approved hi', 'sub_conversation_id' => $subs[0]])
        ->and($titles->count)->toBe(1);

    gpAssertClean();
})->with(['nothing', 'forUser']);
