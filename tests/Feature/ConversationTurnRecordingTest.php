<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
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
        ->and($seen[0]['assistant']->completed_at)->toBeNull()
        ->and($seen[0]['assistant']->steps)->toBe('[]')
        ->and($seen[0]['prompt_messages'])->toBe(1)
        // The second request already saw the first step and its result on the row...
        ->and($seen[1]['assistant']->steps)->json()->toHaveCount(1)->{'0'}->tool_calls->{'0'}->toMatchArray(['id' => 'toolu_1', 'result' => 72019])
        ->and($seen[1]['prompt_messages'])->toBe(2)
        // The completing write closed the row and retitled the conversation...
        ->and($row->id)->toBe($response->assistantMessageId)
        ->and($row->completed_at)->not->toBeNull()
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
        ->and($rows[1]->completed_at)->not->toBeNull()
        ->and(json_decode($rows[1]->meta, true)['provider'])->toBe('backup');
});
