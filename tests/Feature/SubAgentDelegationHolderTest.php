<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request as ToolRequest;

/*
 * proto4: checks specific to the per-delegation holder.
 */

class HoSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Sub-agent.';
    }
}

class HoHelper implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Helper prompted from elsewhere.';
    }
}

#[Provider(['primary', 'backup'])]
class HoFailoverSub implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Fails over.';
    }
}

#[MaxSteps(6)]
class HoParent implements Agent, Conversational, HasTools
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

/** The scoped delegation slot: set only while a sub-agent runs synchronously. */
function hoDelegation(): ?array
{
    return (new ReflectionProperty(AgentTool::class, 'delegation'))->getValue();
}

/** The invocation IDs of the runs currently marked as remembered with their delegations. */
function hoRemembering(): int
{
    return count((new ReflectionProperty(RememberConversation::class, 'remembered'))->getValue());
}

beforeEach(function (): void {
    config(['ai.conversations.remember_delegations' => true]);
});

function hoGroqStreamBody(): string
{
    return collect([
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":1,"total_tokens":6}}',
    ])->map(fn (string $chunk) => "data: {$chunk}\n\n")->join('')."data: [DONE]\n\n";
}

function hoTitleCalls(): object
{
    $counter = (object) ['count' => 0];

    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use ($counter): void {
        if ($event->prompt->agent instanceof ConversationTitleAgent) {
            $counter->count++;
        }
    });

    return $counter;
}

test('H1: a parent stream abandoned in the middle of a delegation leaves no delegation behind', function (): void {
    $user = (object) ['id' => 1];

    HoParent::fake([new ToolCall('call_1', 'HoSub', ['task' => 'one']), 'done']);
    HoSub::fake(['r1']);

    $before = hoRemembering();

    $stream = (new HoParent(fn () => [new HoSub]))->forUser($user)->stream('go');

    $during = null;

    foreach ($stream as $event) {
        if ($event instanceof ToolResultEvent && $event->preliminary) {
            $during = [hoDelegation(), hoRemembering() - $before];

            break;
        }
    }

    unset($stream, $event);

    $afterUnset = hoRemembering() - $before;

    gc_collect_cycles();

    dump('H1: during (slot, marks)='.json_encode($during).' marks after unset='.$afterUnset.' after gc='.(hoRemembering() - $before).' conversations='.DB::table('agent_conversations')->count());

    // Suspended mid-delegation: no static slot holds the sub-agent, the parent and the delegation are marked as running...
    expect($during)->toBe([null, 2])
        // Abandoned: the stream's generators end on release, without waiting for the cycle collector...
        ->and($afterUnset)->toBe(0)
        ->and(hoDelegation())->toBeNull()
        ->and(hoRemembering())->toBe($before);
});

test('H2: an agent prompted from a custom tool keeps the 1.x behaviour: write-back and a title call', function (): void {
    $titles = hoTitleCalls();
    $user = (object) ['id' => 1];

    $tool = new class((new HoHelper)->forUser($user)) implements Tool
    {
        public function __construct(public HoHelper $helper) {}

        public function description(): string
        {
            return 'Asks the helper twice.';
        }

        public function handle(ToolRequest $request): string
        {
            $first = $this->helper->prompt('first')->conversationId;
            $second = $this->helper->prompt('second')->conversationId;

            return json_encode([$first, $second, $this->helper->currentConversation()]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    HoParent::fake([new ToolCall('call_1', class_basename($tool), []), 'done']);
    HoHelper::fake(['a', 'b']);

    $response = (new HoParent(fn () => [$tool]))->forUser($user)->prompt('go');

    [$first, $second, $instance] = json_decode($response->toolResults->first()->result, true);

    dump("H2: helper conversations={$first},{$second} instance={$instance} title calls={$titles->count}");

    expect($first)->toBe($second)->toBe($instance)->not->toBeNull()
        // One title for the parent's conversation, one for the helper's...
        ->and($titles->count)->toBe(2)
        ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('steps')->join(''))->not->toContain('sub_conversation_id');
});

test('H3: another agent prompted while a delegation runs does not take the delegation\'s conversation', function (string $mode): void {
    $user = (object) ['id' => 1];
    $helper = (new HoHelper)->forUser($user);
    $seen = (object) ['ids' => []];

    Event::listen([PromptingAgent::class, StreamingAgent::class], function (PromptingAgent|StreamingAgent $event) use ($helper, $seen): void {
        if ($event->prompt->agent instanceof HoSub) {
            $seen->ids[] = $helper->prompt('side task')->conversationId;
        }
    });

    HoParent::fake([
        new ToolCall('call_1', 'HoSub', ['task' => 'one']),
        new ToolCall('call_2', 'HoSub', ['task' => 'two']),
        'done',
    ]);
    HoSub::fake(['r1', 'r2']);
    HoHelper::fake(['h1', 'h2']);

    $parent = (new HoParent(fn () => [new HoSub]))->forUser($user);

    if ($mode === 'prompt') {
        $response = $parent->prompt('go');
    } else {
        $response = $parent->stream('go');

        foreach ($response as $event) {
            //
        }
    }

    $links = collect(json_decode(DB::table('agent_conversation_messages')->where('conversation_id', $response->conversationId)->where('role', 'assistant')->value('steps'), true))
        ->flatMap(fn (array $step) => $step['tool_calls'])->pluck('sub_conversation_id')->all();

    dump("H3 {$mode}: helper conversations=".json_encode($seen->ids).' links='.json_encode($links).' helper instance='.$helper->currentConversation());

    // The helper is an ordinary agent: it writes back and continues its own conversation, apart from both delegations...
    expect($seen->ids)->toHaveCount(2)
        ->and($seen->ids[0])->toBe($seen->ids[1])->toBe($helper->currentConversation())
        ->and($links)->toHaveCount(2)
        ->and($links)->not->toContain($seen->ids[0])
        ->and($links[0])->not->toBe($links[1]);
})->with(['prompt', 'stream']);

test('H4: a streamed delegation whose sub-agent fails over (the lazily built sub-run) is still stored and linked', function (): void {
    $user = (object) ['id' => 1];

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->push(hoGroqStreamBody(), 200)
        ->push(status: 429)
        ->push(hoGroqStreamBody(), 200);

    $failovers = 0;

    Event::listen(AgentFailedOver::class, function () use (&$failovers): void {
        $failovers++;
    });

    HoParent::fake([
        new ToolCall('call_1', 'HoFailoverSub', ['task' => 'one']),
        new ToolCall('call_2', 'HoFailoverSub', ['task' => 'two']),
        'done',
    ]);

    $stream = (new HoParent(fn () => [new HoFailoverSub]))->forUser($user)->stream('go');

    foreach ($stream as $event) {
        //
    }

    $links = collect(json_decode(DB::table('agent_conversation_messages')->where('conversation_id', $stream->conversationId)->where('role', 'assistant')->value('steps'), true))
        ->flatMap(fn (array $step) => $step['tool_calls'])->map(fn (array $call) => [$call['result'], $call['sub_conversation_id'] ?? null])->all();

    $ownerless = DB::table('agent_conversations')->whereNull('participant_id')->orderBy('id')->pluck('id')->all();

    dump('H4: failovers='.$failovers.' links='.json_encode($links).' ownerless='.json_encode($ownerless).' slot after='.json_encode(hoDelegation()));

    expect($failovers)->toBe(2)
        ->and($ownerless)->toHaveCount(2)
        ->and(array_column($links, 1))->toBe($ownerless)
        ->and(array_column($links, 0))->toBe(['Hello', 'Hello'])
        ->and(hoDelegation())->toBeNull();
});

#[Provider('anthropic')]
#[MaxSteps(6)]
class HoHttpParent extends HoParent
{
    //
}

#[Provider('anthropic')]
class HoHttpSub extends HoSub
{
    //
}

test('H5: the marks of remembered runs go away with their prompts (no agent fakes, which keep prompts)', function (): void {
    $user = (object) ['id' => 1];

    config(['ai.providers.anthropic.key' => 'test-key', 'ai.conversations.generate_title' => false]);

    $message = fn (array $content, string $stop) => [
        'id' => 'msg_'.md5(json_encode($content)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($message([['type' => 'tool_use', 'id' => 'toolu_p1', 'name' => 'HoHttpSub', 'input' => ['task' => 'one']]], 'tool_use'))
        ->push($message([['type' => 'text', 'text' => 'sub done']], 'end_turn'))
        ->push($message([['type' => 'text', 'text' => 'final']], 'end_turn')),
    ]);

    // Marks left by earlier tests go once their (faked, recorded) prompts are collected...
    gc_collect_cycles();

    $before = hoRemembering();

    $response = (new HoHttpParent(fn () => [new HoHttpSub]))->forUser($user)->prompt('go');

    $during = hoRemembering();

    unset($response);
    gc_collect_cycles();

    dump("H5: remembering prompts before={$before} after run={$during} after release=".hoRemembering().' conversations='.DB::table('agent_conversations')->count());

    expect(hoRemembering())->toBe($before)
        ->and(DB::table('agent_conversations')->count())->toBe(2);
});

test('H6: two parent streams iterated in turns, each suspended inside its own delegation of one shared sub-agent instance', function (): void {
    $user = (object) ['id' => 1];
    $sub = new HoSub;

    HoParent::fake([
        new ToolCall('call_a', 'HoSub', ['task' => 'task a']),
        new ToolCall('call_b', 'HoSub', ['task' => 'task b']),
        'done a',
        'done b',
    ]);
    HoSub::fake(['ra', 'rb']);

    $first = (new HoParent(fn () => [$sub]))->forUser($user)->stream('go a')->getIterator();
    $second = (new HoParent(fn () => [$sub]))->forUser($user)->stream('go b')->getIterator();

    // Alternate one event at a time until both finish, so each is repeatedly suspended inside its delegation...
    $slots = [];

    while ($first->valid() || $second->valid()) {
        foreach ([$first, $second] as $stream) {
            if ($stream->valid()) {
                $stream->next();

                $slots[] = hoDelegation();
            }
        }
    }

    $links = DB::table('agent_conversation_messages')->where('role', 'assistant')->whereIn('content', ['done a', 'done b'])->orderBy('id')->pluck('steps')
        ->map(fn (string $steps) => collect(json_decode($steps, true))->flatMap(fn (array $step) => $step['tool_calls'])->first())
        ->map(fn (array $call) => [$call['arguments']['task'], $call['sub_conversation_id'] ?? null])->all();

    $taskOf = fn (?string $id) => DB::table('agent_conversation_messages')->where('conversation_id', $id)->where('role', 'user')->value('content');

    dump('H6: links='.json_encode($links).' slots set between resumptions='.count(array_filter($slots)).' sub instance='.var_export($sub->currentConversation(), true));

    expect($links)->toHaveCount(2)
        ->and($taskOf($links[0][1]))->toBe($links[0][0])
        ->and($taskOf($links[1][1]))->toBe($links[1][0])
        ->and(array_filter($slots))->toBe([])
        ->and($sub->currentConversation())->toBeNull();
});
