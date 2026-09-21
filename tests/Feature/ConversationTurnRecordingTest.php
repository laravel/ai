<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;

function anthropicTurn(array $content, string $stopReason): array
{
    return [
        'id' => 'msg_'.uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => $content,
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

class RowInspector implements Tool
{
    public ?array $callsWhileRunning = null;

    public function description(): string
    {
        return 'Inspects the row it is recorded on.';
    }

    public function handle(ToolRequest $request): string
    {
        $this->callsWhileRunning = json_decode(assistantRowFor(DB::table('agent_conversations')->value('id'))->steps, true)[0]['tool_calls'];

        return 'inspected';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

function assistantRowFor(string $conversationId): ?object
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->orderByDesc('id')
        ->first();
}

test('the conversation, the user message and an open assistant row exist before the first model call', function (): void {
    $seen = [];

    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake(function (Request $request) use (&$seen) {
        $conversationId = DB::table('agent_conversations')->value('id');

        if (str_contains((string) $request->data()['system'], 'Generate a concise 3-5 word title')) {
            return Http::response(anthropicTurn([['type' => 'text', 'text' => 'Number generation']], 'end_turn'));
        }

        $seen[] = [
            'title' => DB::table('agent_conversations')->value('title'),
            'user_rows' => DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'user')->count(),
            'assistant' => assistantRowFor($conversationId),
            'prompt_messages' => collect($request->data()['messages'])->where('role', 'user')->count(),
        ];

        return Http::response(count($seen) === 1
            ? anthropicTurn([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []]], 'tool_use')
            : anthropicTurn([['type' => 'text', 'text' => 'The number is 72019.']], 'end_turn'));
    });

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $response = $agent->prompt('Generate a number', provider: 'anthropic');

    $row = assistantRowFor($response->conversationId);

    expect($seen)->toHaveCount(2)
        // The rows were open on the first request and the request itself carried the prompt once, not once stored and once live...
        ->and($seen[0]['title'])->toBe('Generate a number')
        ->and($seen[0]['user_rows'])->toBe(1)
        ->and($seen[0]['assistant']->status)->toBe('started')
        ->and($seen[0]['assistant']->steps)->toBe('[]')
        ->and($seen[0]['prompt_messages'])->toBe(1)
        // The second request already saw the first step and its result on the row...
        ->and($seen[1]['assistant']->steps)->json()->toHaveCount(1)->{'0'}->tool_calls->{'0'}->toMatchArray(['id' => 'toolu_1', 'result' => 72019])
        ->and($seen[1]['prompt_messages'])->toBe(2)
        // The completing write closed the row and retitled the conversation...
        ->and($row->id)->toBe($response->assistantMessageId)
        ->and($row->status)->toBe('completed')
        ->and($row->content)->toBe('The number is 72019.')
        ->and($row->steps)->json()->toHaveCount(2)
        ->and(DB::table('agent_conversations')->value('title'))->toBe('Number generation')
        // Once the turn is closed the agent's own history includes it again...
        ->and(collect($agent->messages())->map(fn ($message) => $message->role->value)->all())->toBe(['user', 'assistant', 'tool_result', 'assistant']);
});

test('a tool call is on the row before its tool runs and carries its result after', function (): void {
    Config::set('ai.conversations.generate_title', false);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicTurn([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'RowInspector', 'input' => (object) []]], 'tool_use'))
        ->push(anthropicTurn([['type' => 'text', 'text' => 'Done.']], 'end_turn')),
    ]);

    $tool = new RowInspector;

    $agent = new class($tool) implements Agent, Conversational, HasTools
    {
        use Promptable;
        use RemembersConversations;

        public function __construct(protected Tool $tool) {}

        public function instructions(): string
        {
            return 'Use the tool.';
        }

        public function tools(): iterable
        {
            return [$this->tool];
        }
    };

    $response = $agent->forUser((object) ['id' => 1])->prompt('Inspect', provider: 'anthropic');

    $stored = json_decode(assistantRowFor($response->conversationId)->steps, true)[0]['tool_calls'];

    expect($tool->callsWhileRunning)->toHaveCount(1)
        ->and($tool->callsWhileRunning[0])->toMatchArray(['id' => 'toolu_1', 'name' => 'RowInspector'])->not->toHaveKey('result')
        ->and($stored[0])->toMatchArray(['id' => 'toolu_1', 'result' => 'inspected']);
});

test('a failover attempt continues on the rows the first attempt opened', function (): void {
    Config::set('ai.conversations.generate_title', false);

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::fakeSequence()
        ->push(status: 429)
        ->push([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'test',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello from backup'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ]);

    $response = (new RememberingAssistantAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('Hello', provider: ['primary', 'backup']);

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $response->conversationId)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($rows[1]->id)->toBe($response->assistantMessageId)
        ->and($rows[1]->content)->toBe('Hello from backup')
        ->and($rows[1]->status)->toBe('completed')
        ->and(json_decode($rows[1]->meta, true)['provider'])->toBe('backup');
});

test('a failover after a recorded step leaves that attempt on its own row and answers on a fresh one', function (): void {
    Config::set('ai.conversations.generate_title', false);

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    $completion = fn (array $message, string $reason) => [
        'id' => 'chatcmpl-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'test',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $reason]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
    ];

    Http::fakeSequence()
        ->push($completion(['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}']]]], 'tool_calls'))
        ->push(status: 429)
        ->push($completion(['content' => 'Hello from backup'], 'stop'));

    $response = (new RememberingToolUsingAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('Hello', provider: ['primary', 'backup']);

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $response->conversationId)->orderBy('id')->get();

    expect($rows->pluck('role')->all())->toBe(['user', 'assistant', 'assistant'])
        ->and($rows[1]->status)->toBe('failed')
        ->and(json_decode($rows[1]->meta, true)['error'])->not->toBeEmpty()
        ->and($rows[1]->steps)->json()->toHaveCount(1)->{'0'}->tool_calls->{'0'}->toMatchArray(['id' => 'call_1', 'result' => 72019])
        ->and($rows[2]->id)->toBe($response->assistantMessageId)
        ->and($rows[2]->content)->toBe('Hello from backup')
        ->and($rows[2]->steps)->json()->toHaveCount(1);
});

test('a terminal failure lets go of the turn so the agent history is whole again', function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.groq.key', 'test-key');

    Http::fake(['api.groq.com/*' => Http::response(status: 400)]);

    $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1]);

    try {
        $agent->prompt('Hello', provider: 'groq');
    } catch (RequestException) {
        //
    }

    expect(collect($agent->messages())->map(fn ($message) => $message->content)->all())->toBe(['Hello']);
});

test('a resume with nothing paused throws before it stores anything', function (): void {
    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake();

    expect(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt(Decisions::from(['call_1' => true]), provider: 'anthropic'))
        ->toThrow(ApprovalMismatchException::class);

    test()->assertDatabaseEmpty('agent_conversations');
    test()->assertDatabaseEmpty('agent_conversation_messages');

    Http::assertNothingSent();
});

test('a step whose last tool throws keeps the results of the tools that finished', function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTurn([
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FirstNumber', 'input' => (object) []],
        ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'SecondNumber', 'input' => (object) []],
        ['type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'ExplodingTool', 'input' => (object) []],
    ], 'tool_use'))]);

    $agent = new class implements Agent, Conversational, HasTools
    {
        use Promptable;
        use RemembersConversations;

        public function instructions(): string
        {
            return 'Use the tools.';
        }

        public function tools(): iterable
        {
            return [new FirstNumber, new SecondNumber, new ExplodingTool];
        }
    };

    $agent->forUser((object) ['id' => 1]);

    expect(fn () => $agent->prompt('Add them up', provider: 'anthropic'))->toThrow(RuntimeException::class, 'The tool blew up.');

    $row = assistantRowFor(DB::table('agent_conversations')->value('id'));

    expect($row->status)->toBe('failed')
        ->and(json_decode($row->meta, true)['error'])->toBe('The tool blew up.')
        ->and($row->steps)->json()->toHaveCount(1)->{'0'}->tool_calls->toHaveCount(3)
        ->and(json_decode($row->steps, true)[0]['tool_calls'])->sequence(
            fn ($call) => $call->toMatchArray(['id' => 'toolu_1', 'result' => 'one']),
            fn ($call) => $call->toMatchArray(['id' => 'toolu_2', 'result' => 'two']),
            fn ($call) => $call->not->toHaveKey('result'),
        );

    $replayed = collect($agent->messages())->flatMap(fn ($message) => $message->toolResults ?? [])
        ->mapWithKeys(fn ($result) => [$result->id => $result->result]);

    expect($replayed->all())->toBe([
        'toolu_1' => 'one',
        'toolu_2' => 'two',
        'toolu_3' => 'This tool call was interrupted before a result was recorded, so it may or may not have run.',
    ]);
});

class FirstNumber implements Tool
{
    public function description(): string
    {
        return 'Returns one.';
    }

    public function handle(ToolRequest $request): string
    {
        return 'one';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class SecondNumber implements Tool
{
    public function description(): string
    {
        return 'Returns two.';
    }

    public function handle(ToolRequest $request): string
    {
        return 'two';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class ExplodingTool implements Tool
{
    public function description(): string
    {
        return 'Always throws.';
    }

    public function handle(ToolRequest $request): string
    {
        throw new RuntimeException('The tool blew up.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

test('a stream that dies mid-flight marks the turn failed and records the error', function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake(['api.anthropic.com/*' => Http::response(status: 500)]);

    $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1]);

    expect(function () use ($agent): void {
        foreach ($agent->stream('Hello', provider: 'anthropic') as $event) {
            //
        }
    })->toThrow(RequestException::class);

    $row = assistantRowFor(DB::table('agent_conversations')->value('id'));

    expect($row->status)->toBe('failed')
        ->and(json_decode($row->meta, true)['error'] ?? null)->not->toBeEmpty();
});

test('a step is on record even when a listener of its completed event throws', function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTurn([['type' => 'text', 'text' => 'Hello there.']], 'end_turn'))]);

    Event::listen(StepCompleted::class, function (): void {
        throw new RuntimeException('The listener blew up.');
    });

    $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1]);

    expect(fn () => $agent->prompt('Hello', provider: 'anthropic'))->toThrow(RuntimeException::class, 'The listener blew up.');

    $row = assistantRowFor(DB::table('agent_conversations')->value('id'));

    expect($row->status)->toBe('failed')
        ->and($row->steps)->json()->toHaveCount(1)->{'0'}->content->toBe('Hello there.');
});

test('a stream that dies after yielding marks the turn failed even with a provider left to fall back to', function (): void {
    Config::set('ai.conversations.generate_title', false);

    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    $chunk = fn (array $delta, ?string $reason) => 'data: '.json_encode([
        'id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'test',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $reason]],
    ])."\n\n";

    Http::fake(['api.groq.com/*' => Http::response(
        $chunk(['role' => 'assistant', 'content' => 'Hello'], null).$chunk([], 'stop')."data: [DONE]\n\n"
    )]);

    Event::listen(StepCompleted::class, function (): void {
        throw new RateLimitedException('Slow down.');
    });

    $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1]);

    expect(function () use ($agent): void {
        foreach ($agent->stream('Hello', provider: ['primary', 'backup']) as $event) {
            //
        }
    })->toThrow(RateLimitedException::class);

    $row = assistantRowFor(DB::table('agent_conversations')->value('id'));

    expect($row->status)->toBe('failed')
        ->and(json_decode($row->meta, true)['error'] ?? null)->not->toBeEmpty();
});
