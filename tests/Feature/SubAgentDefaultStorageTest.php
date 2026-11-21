<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\ConversationTitleAgent;
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
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

/*
 * Sub-agent delegations stored by default when the parent run is remembered, behind
 * ai.conversations.remember_delegations: every test runs with the switch on and off.
 */

class DsTraitSub implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        messages as storedMessages;
    }

    /** @var list<array{?string, int}> */
    public static array $histories = [];

    public function instructions(): string
    {
        return 'Trait sub-agent.';
    }

    public function messages(): iterable
    {
        $messages = [...$this->storedMessages()];

        static::$histories[] = [$this->currentConversation(), count($messages)];

        return $messages;
    }
}

class DsPlainSub implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Plain sub-agent.';
    }
}

class DsTraitStructuredSub implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Structured trait sub-agent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->string()->required()];
    }
}

class DsPlainStructuredSub implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Structured plain sub-agent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->string()->required()];
    }
}

#[MaxSteps(6)]
class DsTraitMiddleSub implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Delegates further.';
    }

    public function tools(): iterable
    {
        return [new DsTraitSub];
    }
}

#[MaxSteps(6)]
class DsPlainMiddleSub implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Delegates further.';
    }

    public function tools(): iterable
    {
        return [new DsPlainSub];
    }
}

#[Provider('anthropic')]
class DsPlainApprovingSub implements Agent, HasTools
{
    use Promptable;

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
class DsTraitApprovingSub implements Agent, Conversational, HasTools
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

#[MaxSteps(6)]
class DsParent implements Agent, Conversational, HasTools
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
class DsPlainParent implements Agent, HasTools
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

class DsFailingStore extends DatabaseConversationStore
{
    public function __construct(public string $failOn)
    {
        parent::__construct();
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        if ($this->failOn === 'conversation' && $participantType === null) {
            throw new RuntimeException('store down (conversation)');
        }

        return parent::storeConversation($participantType, $participantId, $title, $id);
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        if ($this->failOn === 'assistant' && $participantType === null) {
            throw new RuntimeException('store down (assistant)');
        }

        return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
    }
}

function dsVariant(): string
{
    return config('ai.conversations.remember_delegations') ? 'switch on' : 'switch off';
}

/** With the switch off, a sub-agent given nothing is stored as on 1.x: never, unless it pauses. */
function dsStoredByDefault(string $kind): bool
{
    return (bool) config('ai.conversations.remember_delegations');
}

function dsSubClass(string $kind, bool $structured = false): string
{
    return match ([$kind, $structured]) {
        ['trait', false] => DsTraitSub::class,
        ['plain', false] => DsPlainSub::class,
        ['trait', true] => DsTraitStructuredSub::class,
        ['plain', true] => DsPlainStructuredSub::class,
    };
}

function dsRun(Agent $agent, string $mode, string $text): mixed
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

function dsToolResults(mixed $response, string $mode): array
{
    $results = $mode === 'prompt'
        ? $response->toolResults
        : $response->events->whereInstanceOf(ToolResultEvent::class)->reject->preliminary->map->toolResult->values();

    return $results->map(fn ($result) => ['result' => $result->result, 'failed' => $result->failed, 'sub' => $result->subConversationId])->all();
}

function dsParentToolCalls(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)->where('role', 'assistant')->orderBy('id')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->flatMap(fn (array $step) => $step['tool_calls']))
        ->values()->all();
}

function dsOwnerless(): array
{
    return DB::table('agent_conversations')->whereNull('participant_id')->orderBy('id')->pluck('id')->all();
}

function dsUserConversations(object $user): array
{
    return DB::table('agent_conversations')->where('participant_id', $user->id)->orderBy('id')->pluck('id')->all();
}

function dsMessages(string $conversationId): array
{
    return DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->orderBy('id')->get()->all();
}

function dsTitleCalls(): object
{
    $counter = (object) ['count' => 0];

    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use ($counter): void {
        if ($event->prompt->agent instanceof ConversationTitleAgent) {
            $counter->count++;
        }
    });

    return $counter;
}

function dsAnthropicToolUse(string $id): array
{
    return [
        'id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

class DsContractSub implements Agent, Laravel\Ai\Contracts\RemembersConversations
{
    use Promptable;

    public ?string $id = null;

    public ?object $user = null;

    /** @var list<int> */
    public static array $histories = [];

    public function instructions(): string
    {
        return 'Contract-only sub-agent.';
    }

    public function messages(): iterable
    {
        $messages = $this->id === null ? [] : resolve(ConversationStore::class)->getLatestConversationMessages($this->id, 100)->all();

        static::$histories[] = count($messages);

        return $messages;
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

beforeEach(function (): void {
    DsTraitSub::$histories = [];
    ApprovableNumberGenerator::$invocations = 0;
    ApprovableNumberGenerator::$approvalChecks = 0;
    Config::set('ai.providers.anthropic.key', 'test-key');
});

foreach (['remember_delegations on' => true, 'remember_delegations off' => false] as $label => $rememberDelegations) {
    describe($label, function () use ($rememberDelegations): void {
        beforeEach(fn () => Config::set('ai.conversations.remember_delegations', $rememberDelegations));

        test('D1: remembered parent + sub-agent given nothing: two delegations', function (string $mode, string $kind): void {
            $titles = dsTitleCalls();
            $user = (object) ['id' => 1];
            $sub = dsSubClass($kind);

            DsParent::fake([
                new ToolCall('call_1', class_basename($sub), ['task' => 'one']),
                new ToolCall('call_2', class_basename($sub), ['task' => 'two']),
                'done',
            ]);
            $sub::fake(['r1', 'r2']);

            $response = dsRun((new DsParent(fn () => [new $sub]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);
            $ownerless = dsOwnerless();

            dump(sprintf('D1 %s %s %s: user conversations=%d ownerless=%d links=%s results=%s titles(LLM)=%d titleRows=%s histories=%s',
                dsVariant(), $mode, $kind, count(dsUserConversations($user)), count($ownerless),
                json_encode(array_map(fn ($c) => $c['sub_conversation_id'] ?? null, $calls)), json_encode(dsToolResults($response, $mode)),
                $titles->count, json_encode(DB::table('agent_conversations')->whereNull('participant_id')->pluck('title')->all()),
                json_encode(array_column(DsTraitSub::$histories, 1))));

            expect(dsUserConversations($user))->toBe([$response->conversationId])
                ->and($titles->count)->toBe(1)
                ->and(array_column($calls, 'result'))->toBe(['r1', 'r2']);

            if (! dsStoredByDefault($kind)) {
                expect($ownerless)->toBe([])->and($calls[0])->not->toHaveKey('sub_conversation_id');

                return;
            }

            expect($ownerless)->toHaveCount(2)
                ->and(array_column($calls, 'sub_conversation_id'))->toBe($ownerless)
                ->and(array_column(dsToolResults($response, $mode), 'sub'))->toBe($ownerless)
                ->and(dsMessages($ownerless[0]))->toHaveCount(2)
                ->and(dsMessages($ownerless[1]))->toHaveCount(2)
                ->and(dsMessages($ownerless[0])[0]->agent)->toBe($sub)
                ->and(dsMessages($ownerless[0])[0]->participant_id)->toBeNull()
                ->and(dsMessages($ownerless[1])[0]->content)->toBe('two')
                ->and(DB::table('agent_conversations')->whereNull('participant_id')->pluck('title')->all())->toBe(['one', 'two']);

            if ($kind === 'trait') {
                expect(array_column(DsTraitSub::$histories, 1))->toBe([0, 0]);
            }
        })->with(['prompt', 'stream'])->with(['trait', 'plain']);

        test('D1b: a parent continuing an ownerless conversation is remembered too', function (string $kind): void {
            $sub = dsSubClass($kind);
            $parentId = resolve(ConversationStore::class)->storeConversation(null, null, 'parent');

            DsParent::fake([new ToolCall('call_1', class_basename($sub), ['task' => 'one']), 'done']);
            $sub::fake(['r1']);

            (new DsParent(fn () => [new $sub]))->continue($parentId)->prompt('go');

            $calls = dsParentToolCalls($parentId);

            dump(sprintf('D1b %s %s: ownerless=%d link=%s', dsVariant(), $kind, count(dsOwnerless()), json_encode($calls[0]['sub_conversation_id'] ?? null)));

            expect(count(dsOwnerless()))->toBe(dsStoredByDefault($kind) ? 2 : 1);
        })->with(['trait', 'plain']);

        test('D2: a parent that is not remembered stores nothing for its delegations', function (string $mode, string $kind, string $parent): void {
            $sub = dsSubClass($kind);
            $parentClass = $parent === 'plain parent' ? DsPlainParent::class : DsParent::class;

            $parentClass::fake([
                new ToolCall('call_1', class_basename($sub), ['task' => 'one']),
                new ToolCall('call_2', class_basename($sub), ['task' => 'two']),
                'done',
            ]);
            $sub::fake(['r1', 'r2']);

            $response = dsRun(new $parentClass(fn () => [new $sub]), $mode, 'go');

            dump(sprintf('D2 %s %s %s %s: conversations=%d messages=%d results=%s', dsVariant(), $mode, $kind, $parent,
                DB::table('agent_conversations')->count(), DB::table('agent_conversation_messages')->count(), json_encode(dsToolResults($response, $mode))));

            expect(DB::table('agent_conversations')->count())->toBe(0)
                ->and(DB::table('agent_conversation_messages')->count())->toBe(0)
                ->and(array_column(dsToolResults($response, $mode), 'sub'))->toBe([null, null]);
        })->with(['prompt', 'stream'])->with(['trait', 'plain'])->with(['plain parent', 'trait parent given nothing']);

        test('D3: an explicit forUser() on the sub-agent stores each delegation under that user', function (string $mode): void {
            $titles = dsTitleCalls();
            $user = (object) ['id' => 1];
            $other = (object) ['id' => 2];

            DsParent::fake([
                new ToolCall('call_1', 'DsTraitSub', ['task' => 'one']),
                new ToolCall('call_2', 'DsTraitSub', ['task' => 'two']),
                'done',
            ]);
            DsTraitSub::fake(['r1', 'r2']);

            $response = dsRun((new DsParent(fn () => [(new DsTraitSub)->forUser($other)]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);

            dump(sprintf('D3 %s %s: user=%d other=%d ownerless=%d titles(LLM)=%d histories=%s', dsVariant(), $mode,
                count(dsUserConversations($user)), count(dsUserConversations($other)), count(dsOwnerless()), $titles->count, json_encode(array_column(DsTraitSub::$histories, 1))));

            expect(dsUserConversations($user))->toBe([$response->conversationId])
                ->and(dsUserConversations($other))->toHaveCount(2)
                ->and(array_column($calls, 'sub_conversation_id'))->toBe(dsUserConversations($other))
                ->and(dsOwnerless())->toBe([])
                ->and($titles->count)->toBe(1)
                ->and(array_column(DsTraitSub::$histories, 1))->toBe([0, 0]);
        })->with(['prompt', 'stream']);

        test('D4: an explicit continue() on the sub-agent shares one conversation', function (string $mode): void {
            $user = (object) ['id' => 1];

            DsTraitSub::fake(['seed', 'r1', 'r2']);
            $seeded = (new DsTraitSub)->continue(resolve(ConversationStore::class)->storeConversation(null, null, 'shared'))->prompt('seed');
            DsTraitSub::$histories = [];

            DsParent::fake([
                new ToolCall('call_1', 'DsTraitSub', ['task' => 'one']),
                new ToolCall('call_2', 'DsTraitSub', ['task' => 'two']),
                'done',
            ]);

            $response = dsRun((new DsParent(fn () => [(new DsTraitSub)->continue($seeded->conversationId)]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);

            dump(sprintf('D4 %s %s: ownerless=%d shared rows=%d histories=%s links=%s', dsVariant(), $mode, count(dsOwnerless()),
                count(dsMessages($seeded->conversationId)), json_encode(array_column(DsTraitSub::$histories, 1)), json_encode(array_column($calls, 'sub_conversation_id'))));

            expect(dsOwnerless())->toBe([$seeded->conversationId])
                ->and(dsMessages($seeded->conversationId))->toHaveCount(6)
                ->and(array_column(DsTraitSub::$histories, 1))->toBe([2, 4])
                ->and(array_column($calls, 'sub_conversation_id'))->toBe([$seeded->conversationId, $seeded->conversationId]);
        })->with(['prompt', 'stream']);

        test('D5: structured-output sub-agents, including the streaming parent\'s handle() fallback', function (string $mode, string $kind): void {
            $user = (object) ['id' => 1];
            $sub = dsSubClass($kind, structured: true);

            DsParent::fake([
                new ToolCall('call_1', class_basename($sub), ['task' => 'one']),
                new ToolCall('call_2', class_basename($sub), ['task' => 'two']),
                'done',
            ]);
            $sub::fake([['answer' => 'a'], ['answer' => 'b']]);

            $response = dsRun((new DsParent(fn () => [new $sub]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);
            $ownerless = dsOwnerless();

            dump(sprintf('D5 %s %s %s: ownerless=%d links=%s rows=%s', dsVariant(), $mode, $kind, count($ownerless),
                json_encode(array_map(fn ($c) => $c['sub_conversation_id'] ?? null, $calls)), json_encode(array_map(fn ($id) => count(dsMessages($id)), $ownerless))));

            expect($ownerless)->toHaveCount(dsStoredByDefault($kind) ? 2 : 0);

            if (dsStoredByDefault($kind)) {
                expect(array_column($calls, 'sub_conversation_id'))->toBe($ownerless)
                    ->and(dsMessages($ownerless[0]))->toHaveCount(2)
                    ->and(dsMessages($ownerless[1]))->toHaveCount(2);
            }
        })->with(['prompt', 'stream'])->with(['trait', 'plain']);

        test('D6: a storage error in the default sub-conversation', function (string $mode, string $kind, string $failOn): void {
            $user = (object) ['id' => 1];
            $sub = dsSubClass($kind);

            app()->instance(ConversationStore::class, new DsFailingStore($failOn));

            DsParent::fake([new ToolCall('call_1', class_basename($sub), ['task' => 'one']), 'done']);
            $sub::fake(['r1']);

            $response = dsRun((new DsParent(fn () => [new $sub]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);

            dump(sprintf('D6 %s %s %s fail=%s: parent text=%s parent call=%s ownerless=%s', dsVariant(), $mode, $kind, $failOn,
                json_encode($response->text), json_encode($calls[0]), json_encode(array_map(fn ($id) => count(dsMessages($id)), dsOwnerless()))));

            expect($response->text)->toBe('done');

            if (dsStoredByDefault($kind)) {
                expect($calls[0]['failed'] ?? false)->toBeTrue()
                    ->and($calls[0]['result'])->toContain('store down');
            } else {
                expect($calls[0]['result'])->toBe('r1');
            }
        })->with(['prompt', 'stream'])->with(['trait', 'plain'])->with(['conversation', 'assistant']);

        test('D7: database queries and LLM calls per delegation', function (string $mode, string $kind, string $parent): void {
            $user = (object) ['id' => 1];
            $sub = dsSubClass($kind);
            $parentClass = $parent === 'plain parent' ? DsPlainParent::class : DsParent::class;

            $calls = (object) ['title' => 0, 'sub' => 0];
            foreach ([AgentPrompted::class, AgentStreamed::class] as $eventClass) {
                Event::listen($eventClass, function ($event) use ($calls, $sub): void {
                    match (true) {
                        $event->prompt->agent instanceof ConversationTitleAgent => $calls->title++,
                        $event->prompt->agent instanceof $sub => $calls->sub++,
                        default => null,
                    };
                });
            }

            $state = (object) ['inside' => false, 'queries' => []];
            Event::listen(InvokingTool::class, fn (InvokingTool $event) => $event->tool instanceof AgentTool ? $state->inside = true : null);
            Event::listen(ToolInvoked::class, fn (ToolInvoked $event) => $event->tool instanceof AgentTool ? $state->inside = false : null);
            Event::listen(ToolFailed::class, fn (ToolFailed $event) => $event->tool instanceof AgentTool ? $state->inside = false : null);
            DB::listen(function ($query) use ($state): void {
                if ($state->inside) {
                    $state->queries[] = strtolower(strtok($query->sql, ' '));
                }
            });

            $parentClass::fake([new ToolCall('call_1', class_basename($sub), ['task' => 'one']), 'done']);
            $sub::fake(['r1']);

            $agent = new $parentClass(fn () => [new $sub]);
            dsRun($parent === 'plain parent' ? $agent : $agent->forUser($user), $mode, 'go');

            $counts = array_count_values($state->queries);
            ksort($counts);

            dump(sprintf('D7 %s %s %s %s: queries during the delegation=%s (total %d) sub LLM calls=%d title LLM calls (whole run)=%d',
                dsVariant(), $mode, $kind, $parent, json_encode($counts), count($state->queries), $calls->sub, $calls->title));

            expect($calls->sub)->toBe(1);
        })->with(['prompt', 'stream'])->with(['trait', 'plain'])->with(['plain parent', 'remembered parent']);

        test('D8 (informational): a sub-agent\'s own delegations under a remembered parent', function (string $mode, string $kind): void {
            $user = (object) ['id' => 1];
            [$middle, $leaf] = $kind === 'trait' ? [DsTraitMiddleSub::class, DsTraitSub::class] : [DsPlainMiddleSub::class, DsPlainSub::class];

            DsParent::fake([new ToolCall('call_1', class_basename($middle), ['task' => 'one']), 'done']);
            $middle::fake([new ToolCall('call_m', class_basename($leaf), ['task' => 'leaf']), 'middle done']);
            $leaf::fake(['leaf done']);

            $response = dsRun((new DsParent(fn () => [new $middle]))->forUser($user), $mode, 'go');

            $middleId = dsParentToolCalls($response->conversationId)[0]['sub_conversation_id'] ?? null;
            $leafLink = $middleId === null ? null : (dsParentToolCalls($middleId)[0]['sub_conversation_id'] ?? null);

            dump(sprintf('D8 %s %s %s: ownerless=%d middle link=%s leaf link (on middle\'s tool call)=%s', dsVariant(), $mode, $kind, count(dsOwnerless()),
                var_export($middleId, true), var_export($leafLink, true)));

            expect(true)->toBeTrue();
        })->with(['prompt', 'stream'])->with(['trait', 'plain']);

        test('D9: an agent prompted from a custom tool (not an AgentTool) is not stored by default', function (string $kind): void {
            $user = (object) ['id' => 1];
            $sub = dsSubClass($kind);

            $tool = new class($sub) implements Tool
            {
                public function __construct(public string $sub) {}

                public function description(): string
                {
                    return 'Asks a helper.';
                }

                public function handle(ToolRequest $request): string
                {
                    return (new $this->sub)->prompt('helper task')->text;
                }

                public function schema(JsonSchema $schema): array
                {
                    return [];
                }
            };

            DsParent::fake([new ToolCall('call_1', class_basename($tool), []), 'done']);
            $sub::fake(['helped']);

            (new DsParent(fn () => [$tool]))->forUser($user)->prompt('go');

            dump(sprintf('D9 %s %s: ownerless=%d', dsVariant(), $kind, count(dsOwnerless())));

            expect(dsOwnerless())->toBe([]);
        })->with(['trait', 'plain']);

        test('D10: a sub-agent that pauses on an approval under a remembered parent', function (string $kind): void {
            $user = (object) ['id' => 1];
            $sub = $kind === 'trait' ? DsTraitApprovingSub::class : DsPlainApprovingSub::class;

            Http::fake(['api.anthropic.com/*' => Http::sequence()->push(dsAnthropicToolUse('toolu_s1'))]);

            DsParent::fake([new ToolCall('call_1', class_basename($sub), ['task' => 'one']), 'done']);

            $response = (new DsParent(fn () => [new $sub]))->forUser($user)->prompt('go');

            $calls = dsParentToolCalls($response->conversationId);

            dump(sprintf('D10 %s %s: parent call=%s ownerless=%s', dsVariant(), $kind, json_encode($calls[0]),
                json_encode(array_map(fn ($id) => array_map(fn ($row) => $row->role.':'.$row->status, dsMessages($id)), dsOwnerless()))));

            expect(true)->toBeTrue();
        })->with(['trait', 'plain']);

        test('D11 (informational): what knows a NEW parent\'s conversation id while its sub-agent runs', function (string $mode): void {
            $user = (object) ['id' => 1];
            $seen = (object) ['value' => null];
            $holder = (object) ['stream' => null];

            $tool = new class($seen, $holder) implements Tool
            {
                public function __construct(public object $seen, public object $holder) {}

                public function description(): string
                {
                    return 'Looks around.';
                }

                public function handle(ToolRequest $request): string
                {
                    $this->seen->value = [
                        'parentInvocation' => ParentInvocation::current(),
                        'conversationRows' => DB::table('agent_conversations')->count(),
                        'parentStreamConversationId' => $this->holder->stream?->conversationId,
                    ];

                    return 'looked';
                }

                public function schema(JsonSchema $schema): array
                {
                    return [];
                }
            };

            DsParent::fake([new ToolCall('call_1', class_basename($tool), []), 'done']);

            $parent = (new DsParent(fn () => [$tool]))->forUser($user);

            if ($mode === 'prompt') {
                $response = $parent->prompt('go');
            } else {
                $response = $holder->stream = $parent->stream('go');

                foreach ($response as $event) {
                    //
                }
            }

            dump(sprintf('D11 %s %s: during sub-run=%s final parent conversation=%s', dsVariant(), $mode, json_encode($seen->value), $response->conversationId));

            expect($seen->value['conversationRows'])->toBe(0);
        })->with(['prompt', 'stream']);

        test('D12: a sub-agent that fails before its first step under a remembered parent', function (string $mode, string $kind): void {
            $user = (object) ['id' => 1];
            $sub = $kind === 'trait' ? DsTraitApprovingSub::class : DsPlainApprovingSub::class;

            Http::fake(['api.anthropic.com/*' => Http::sequence()->pushStatus(500)->pushStatus(500)->pushStatus(500)]);

            DsParent::fake([new ToolCall('call_1', class_basename($sub), ['task' => 'one']), 'done']);

            $response = dsRun((new DsParent(fn () => [new $sub]))->forUser($user), $mode, 'go');

            $calls = dsParentToolCalls($response->conversationId);

            dump(sprintf('D12 %s %s %s: parent call=%s ownerless=%d', dsVariant(), $mode, $kind, json_encode($calls[0]), count(dsOwnerless())));

            expect($calls[0]['failed'] ?? false)->toBeTrue()
                ->and(dsOwnerless())->toBe([]);
        })->with(['prompt', 'stream'])->with(['trait', 'plain']);

        test('D13 (informational): a contract-only RemembersConversations sub-agent given nothing, under a remembered parent', function (string $mode): void {
            $user = (object) ['id' => 1];
            DsContractSub::$histories = [];

            DsParent::fake([
                new ToolCall('call_1', 'DsContractSub', ['task' => 'one']),
                new ToolCall('call_2', 'DsContractSub', ['task' => 'two']),
                'done',
            ]);
            DsContractSub::fake(['r1', 'r2']);

            $sub = new DsContractSub;

            $error = null;

            try {
                $response = dsRun((new DsParent(fn () => [$sub]))->forUser($user), $mode, 'go');
                $links = array_map(fn ($c) => $c['sub_conversation_id'] ?? null, dsParentToolCalls($response->conversationId));
            } catch (Throwable $e) {
                $error = $e::class.': '.$e->getMessage();
                $links = null;
            }

            dump(sprintf('D13 %s %s: error=%s ownerless=%d rows=%s histories=%s links=%s instance id=%s', dsVariant(), $mode, var_export($error, true), count(dsOwnerless()),
                json_encode(array_map(fn ($id) => count(dsMessages($id)), dsOwnerless())), json_encode(DsContractSub::$histories), json_encode($links), var_export($sub->id, true)));

            expect(true)->toBeTrue();
        })->with(['prompt', 'stream']);
    });
}
