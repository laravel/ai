<?php

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('it writes conversations to the default tables', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->where('title', 'Hello')->exists())->toBeTrue();
});

test('it writes to overridden table names from config', function (): void {
    Config::set('ai.conversations.tables.conversations', 'custom_conversations');
    Config::set('ai.conversations.tables.messages', 'custom_conversation_messages');

    createConversationSchema();

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Hello');

    expect(DB::table('custom_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it routes queries through the configured connection', function (): void {
    Config::set('database.connections.secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    createConversationSchema('secondary');

    $store = new DatabaseConversationStore('secondary');
    $conversationId = $store->storeConversation('user', 1, 'Hello');

    expect(DB::connection('secondary')->table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it paginates conversation messages newest first, scoped to the conversation', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Target transcript');
    $otherConversationId = $store->storeConversation('user', 1, 'Other transcript');

    insertStoredConversationMessages($conversationId, ['message-001', 'message-003', 'message-005']);
    insertStoredConversationMessages($otherConversationId, ['message-002', 'message-004']);

    $page = $store->paginateConversationMessages($conversationId, 10);

    expect($store)->toBeInstanceOf(PaginatesConversations::class)
        ->and($page)->toBeInstanceOf(CursorPaginator::class)
        ->and($page->items())->toContainOnlyInstancesOf(StoredMessage::class)
        ->and(collect($page->items())->pluck('id')->all())->toBe(['message-005', 'message-003', 'message-001']);
});

test('it verifies which participant a conversation was stored for', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Mine');

    expect($store)->toBeInstanceOf(VerifiesConversationOwnership::class)
        ->and($store->conversationBelongsTo($conversationId, 'user', 1))->toBeTrue()
        ->and($store->conversationBelongsTo($conversationId, 'user', 2))->toBeFalse()
        ->and($store->conversationBelongsTo($conversationId, 'team', 1))->toBeFalse();
});

test('it compares a participant key stored as a string against an integer', function (): void {
    $store = new DatabaseConversationStore;

    // The key comes from another table, so the column it was written to and the value a caller holds need not agree on type...
    expect($store->conversationBelongsTo($store->storeConversation('user', '7', 'Mine'), 'user', 7))->toBeTrue()
        ->and($store->conversationBelongsTo($store->storeConversation('user', 7, 'Mine'), 'user', '7'))->toBeTrue();
});

test('it refuses a half-matching participant', function (): void {
    $store = new DatabaseConversationStore;
    $ownerless = $store->storeConversation(null, null, 'Ownerless');
    $owned = $store->storeConversation('user', 1, 'Owned');

    // Filtering the pair in the query instead would answer this first one yes, since a null id nulls both columns and drops the type that was asked about...
    expect($store->conversationBelongsTo($ownerless, 'user', null))->toBeFalse()
        ->and($store->conversationBelongsTo($ownerless, null, 1))->toBeFalse()
        ->and($store->conversationBelongsTo($owned, 'user', null))->toBeFalse()
        ->and($store->conversationBelongsTo($owned, null, 1))->toBeFalse();
});

test('it refuses a conversation that does not exist', function (): void {
    expect((new DatabaseConversationStore)->conversationBelongsTo('missing-conversation', 'user', 1))->toBeFalse();
});

test('it matches an ownerless conversation only to a null participant', function (): void {
    $store = new DatabaseConversationStore;
    $ownerless = $store->storeConversation(null, null, 'Ownerless');

    // A turn that pauses for approval is remembered whether or not the agent was given a participant, so a conversation belonging to nobody is a stored state rather than an edge case...
    expect($store->conversationBelongsTo($ownerless, null, null))->toBeTrue()
        ->and($store->conversationBelongsTo($ownerless, 'user', 1))->toBeFalse();
});

test('it decodes the stored JSON columns', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Transcript');

    // The JSON columns are why this returns a value object: a caller rendering a transcript should not be decoding storage...
    DB::table('agent_conversation_messages')->insert([
        ...storedConversationMessageAttributes('message-001', $conversationId, 'Saved the note.'),
        'role' => 'assistant',
        'meta' => json_encode(['provider' => 'openai', 'citations' => [['url' => 'https://laravel.com']]]),
        'steps' => json_encode([assistantStep([['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1]]])]),
        'usage' => json_encode(['input_tokens' => 12]),
    ]);

    $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

    expect($message->meta['provider'])->toBe('openai')
        ->and($message->meta['citations'][0]['url'])->toBe('https://laravel.com')
        ->and($message->toolCalls()[0]['name'])->toBe('save_note')
        ->and($message->usage['input_tokens'])->toBe(12)
        ->and($message->toolResults())->toBe([])
        ->and($message->status)->toBe(MessageStatus::Completed)
        ->and($message->createdAt)->toBeInstanceOf(CarbonInterface::class);
});

test('a stored tool result is narrowed to its own keys so provider replay state stays out of a rendered transcript', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Transcript');

    DB::table('agent_conversation_messages')->insert([
        ...storedConversationMessageAttributes('message-001', $conversationId, 'Saved the note.'),
        'role' => 'assistant',
        'steps' => json_encode([assistantStep(
            [['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'reasoning_id' => 'rs_1', 'reasoning_encrypted_content' => 'gAAAAA']],
            [['id' => 'call-1', 'result' => 'Saved']],
        )]),
    ]);

    $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

    expect($message->toolResults())->toBe([
        ['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'result' => 'Saved'],
    ])->and($message->toolCalls()[0])->toHaveKey('reasoning_encrypted_content');
});

test('it advances to the next cursor page', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Transcript');

    insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

    $firstPage = $store->paginateConversationMessages($conversationId, 2);

    request()->query->set('cursor', $firstPage->nextCursor()?->encode());

    $secondPage = $store->paginateConversationMessages($conversationId, 2);

    expect(collect($firstPage->items())->pluck('id')->all())->toBe(['message-005', 'message-004'])
        ->and(collect($secondPage->items())->pluck('id')->all())->toBe(['message-003', 'message-002']);
});

test('it reads the cursor from the given query parameter name', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Transcript');

    insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

    $firstPage = $store->paginateConversationMessages($conversationId, 2, 'support');

    // Two transcripts on one page would otherwise page in lockstep, both reading `?cursor=`...
    request()->query->set('support', $firstPage->nextCursor()?->encode());
    request()->query->set('cursor', 'ignored');

    $secondPage = $store->paginateConversationMessages($conversationId, 2, 'support');

    expect(collect($secondPage->items())->pluck('id')->all())->toBe(['message-003', 'message-002']);
});

test('it accepts a cursor passed directly, without a request', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Transcript');

    insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

    $firstPage = $store->paginateConversationMessages($conversationId, 2);

    $secondPage = $store->paginateConversationMessages($conversationId, 2, cursor: $firstPage->nextCursor());

    expect(collect($secondPage->items())->pluck('id')->all())->toBe(['message-003', 'message-002']);
});

test('it reports the tool calls a paused turn is waiting on', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Paused');

    insertPausedConversationTurn($conversationId, 'message-001', [
        ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']],
        ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => ['to' => 'a@b.test']],
    ], ['call-1' => 'Deletes a file.', 'call-2' => null]);

    $pending = $store->pendingApprovalsFor($conversationId);

    expect($store)->toBeInstanceOf(ResolvesPendingApprovals::class)
        ->and($pending)->toHaveCount(2)
        ->and($pending[0])->toBeInstanceOf(PendingApproval::class)
        ->and($pending[0]->id)->toBe('call-1')
        ->and($pending[0]->tool)->toBe('DeleteFile')
        ->and($pending[0]->arguments)->toBe(['path' => 'a.txt'])
        ->and($pending[0]->reason)->toBe('Deletes a file.')
        ->and($pending[1]->reason)->toBeNull();
});

test('it drops a call that already has a result', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Resumed');

    $calls = [
        ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []],
        ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => []],
    ];

    insertAssistantTurn($conversationId, 'message-001', 'Waiting on you.', [
        assistantStep($calls, [['id' => 'call-1', 'name' => 'DeleteFile', 'result' => 'Deleted.']]),
    ], ['call-1' => null, 'call-2' => null]);

    expect(collect($store->pendingApprovalsFor($conversationId))->pluck('id')->all())->toBe(['call-2']);
});

test('it reports nothing when the newest turn is not paused', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Answered');

    insertStoredConversationMessages($conversationId, ['message-001']);

    expect($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($store->pendingApprovalsFor('missing-conversation'))->toBe([]);
});

test('it stores one step per model round-trip from a remembered agent prompt', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'id' => 'call_123',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'The number is 72019']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];
    $conversationId = (new DatabaseConversationStore)->storeConversation('user', $user->id, 'Tool conversation');

    (new RememberingToolUsingAgent)
        ->continue($conversationId, $user)
        ->prompt('Generate a random number', provider: 'gemini');

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    expect(DB::table('agent_conversation_messages')->where('role', 'user')->value('steps'))->toBe('[]')
        ->and($record->content)->toBe('The number is 72019')
        ->and($record->steps)->json()->toHaveCount(2)->sequence(
            fn ($step) => $step->toMatchArray(['replay_blocks' => []])->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call_123', 'name' => 'FixedNumberGenerator', 'result' => '72019']),
            fn ($step) => $step->toMatchArray(['tool_calls' => [], 'replay_blocks' => []]),
        );
});

test('it preserves the gemini thought signature across a persisted tool conversation', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => ['id' => 'call_123', 'name' => 'FixedNumberGenerator', 'args' => (object) []],
                            'thoughtSignature' => 'sig_persist_777',
                        ]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.6-flash',
            ]),
            Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'The number is 72019']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.6-flash',
            ]),
            Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'The second number is 99']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.6-flash',
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];
    $conversationId = (new DatabaseConversationStore)->storeConversation('user', $user->id, 'Tool conversation');

    (new RememberingToolUsingAgent)->continue($conversationId, $user)->prompt('Generate a random number', provider: 'gemini');

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
    $storedCall = json_decode((string) $record->steps, true)[0]['tool_calls'][0];

    expect($storedCall['thought_signature'])->toBe('sig_persist_777')
        ->and(json_decode((string) $record->steps, true)[0]['replay_blocks'])->toBe([]);

    (new RememberingToolUsingAgent)->continue($conversationId, $user)->prompt('Generate another', provider: 'gemini');

    $recorded = Http::recorded();
    $followUpContents = $recorded[count($recorded) - 1][0]->data()['contents'];

    $signatures = [];

    foreach ($followUpContents as $content) {
        if (($content['role'] ?? null) === 'model') {
            foreach ($content['parts'] as $part) {
                if (isset($part['functionCall'])) {
                    $signatures[] = $part['thoughtSignature'] ?? null;
                }
            }
        }
    }

    // Replayed to Gemini on the second turn, without which the request 400s...
    expect($signatures)->toBe(['sig_persist_777']);
});

test('it stores a response built without steps as a single step of lists', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Check my order status.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', 'The order has shipped.', new TextUsage, new Meta);
    $response->toolCalls = collect([
        2 => new ToolCall('call-1', 'lookup_order', ['id' => 1]),
        8 => new ToolCall('call-2', 'lookup_carrier', ['id' => 1]),
    ]);
    $response->toolResults = collect([
        2 => new ToolResult('call-1', 'lookup_order', ['id' => 1], ['status' => 'shipped']),
        8 => new ToolResult('call-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS']),
    ]);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

    expect($steps)->json()->toHaveCount(1)
        ->and($steps)->json()->{'0'}->tool_calls->toBeList()->sequence(
            fn ($toolCall) => $toolCall->id->toBe('call-1')->result->toBe(['status' => 'shipped']),
            fn ($toolCall) => $toolCall->id->toBe('call-2')->result->toBe(['carrier' => 'UPS']),
        );
});

test('it scopes the latest conversation lookup to conversations the agent has participated in', function (): void {
    $store = new DatabaseConversationStore;

    $first = $store->storeConversation('user', 1, 'First');
    $second = $store->storeConversation('user', 1, 'Second');

    $insertMessage = fn (string $id, string $conversationId, string $agent) => DB::table('agent_conversation_messages')->insert([
        ...storedConversationMessageAttributes($id, $conversationId, 'Hello'),
        'agent' => $agent,
    ]);

    $insertMessage('message-1', $first, ToolUsingAgent::class);
    $insertMessage('message-2', $second, RememberingToolUsingAgent::class);

    expect($store->latestConversationId('user', 1, ToolUsingAgent::class))->toBe($first)
        ->and($store->latestConversationId('user', 1, RememberingToolUsingAgent::class))->toBe($second)
        ->and($store->latestConversationId('user', 1, 'App\\Agents\\Unknown'))->toBeNull();

    $insertMessage('message-3', $second, ToolUsingAgent::class);

    expect($store->latestConversationId('user', 1, ToolUsingAgent::class))->toBe($second);
});

test('it round trips tool result failure status through storage', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Where is Berlin?',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', '', new TextUsage, new Meta);
    $response->toolCalls = collect([new ToolCall('call-1', 'query-resources', [])]);
    $response->toolResults = collect([
        new ToolResult('call-1', 'query-resources', [], 'Tool not found', failed: true),
    ]);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ?->toolResults
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->successful())->toBeFalse()
        ->and($result->error())->toBe('Tool not found');
});

test('it stores a tool result id on the call that made it', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');
    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Write the file',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-1', 'Wrote it.', new TextUsage, new Meta('openai', 'gpt-5')))
        ->withSteps(collect([new Step(
            'Wrote it.',
            [new ToolCall('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'result-1')],
            [new ToolResult('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'Wrote 5 bytes.', 'result-1')],
            FinishReason::Stop,
            new TextUsage,
            new Meta('openai', 'gpt-5'),
            '',
            [],
        )]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $stored = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

    expect(json_decode($stored, true)[0]['tool_calls'])->toHaveCount(1)
        ->and(json_decode($stored, true)[0]['tool_calls'][0])->toMatchArray([
            'id' => 'call-1',
            'name' => 'WriteFile',
            'arguments' => ['path' => 'a.txt', 'contents' => 'alpha'],
            'result' => 'Wrote 5 bytes.',
            'result_id' => 'result-1',
        ]);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ?->toolResults
        ->first();

    expect($result->arguments)->toBe(['path' => 'a.txt', 'contents' => 'alpha'])
        ->and($result->result)->toBe('Wrote 5 bytes.')
        ->and($result->resultId)->toBe('result-1');
});

test('it treats tool results stored before the failed flag as successful', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => [], 'result' => 'Berlin', 'result_id' => null]],
        ),
    ]);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ?->toolResults
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->successful())->toBeTrue()
        ->and($result->error())->toBeNull();
});

test('a bare rejection resume does not persist a blank assistant row', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'The user rejected this tool call.', 'result_id' => null]],
        ),
    ], []);

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        '',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => Decision::reject()]),
    );

    $response = new AgentResponse('invocation-id', '', new TextUsage, new Meta);
    $response->toolResults = collect([
        new ToolResult('call-1', 'DeleteFile', [], 'The user rejected this tool call.'),
    ]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    expect($messageId)->toBe('paused-1')
        ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->count())->toBe(1);
});

test('a resume folds its steps, text and usage into the paused row', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'Deleted', 'result_id' => null]],
        ),
    ], []);

    DB::table('agent_conversation_messages')->where('id', 'paused-1')->update(['usage' => json_encode(['input_tokens' => 10, 'output_tokens' => 5])]);

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        '',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = new AgentResponse('invocation-id', 'Done.', new TextUsage(20, 7), new Meta('openai', 'gpt-5'));
    $response->steps = collect([new Step('Done.', [], [], FinishReason::Stop, new TextUsage(20, 7), new Meta, '', [])]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

    expect($messageId)->toBe('paused-1')
        ->and($row->content)->toBe('Done.')
        ->and($row->steps)->json()->toHaveCount(2)->{'1'}->content->toBe('Done.')
        ->and($row->usage)->json()->toMatchArray(['input_tokens' => 30, 'output_tokens' => 12])
        ->and($row->meta)->json()->toMatchArray(['provider' => 'openai', 'model' => 'gpt-5']);
});

test('a resume keeps the citations the paused half of the turn collected', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', '', [
        assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
    ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'citations' => [(new UrlCitation('https://laravel.com/docs/ai'))->toArray()]]);

    $prompt = new AgentPrompt(new ToolUsingAgent, '', [], Mockery::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $meta = new Meta('anthropic', 'claude-sonnet-4-6', collect([new UrlCitation('https://laravel.com/docs/mcp')]));

    $response = (new AgentResponse('invocation-id', 'Deleted.', new TextUsage, $meta))->withSteps(collect([
        new Step('Deleted.', [], [], FinishReason::Stop, new TextUsage, $meta, '', []),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    expect(DB::table('agent_conversation_messages')->where('id', 'paused-1')->value('meta'))->json()
        ->model->toBe('claude-sonnet-4-6')
        ->citations->toHaveCount(2)
        ->citations->{'0'}->url->toBe('https://laravel.com/docs/ai')
        ->citations->{'1'}->url->toBe('https://laravel.com/docs/mcp');
});

test('a fold that recorded no result does not leave the row reporting a pending approval', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', 'Waiting.', [
        assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
    ], ['call-1' => 'Deletes a file']);

    $prompt = new AgentPrompt(new ToolUsingAgent, '', [], Mockery::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', 'Done.', new TextUsage, new Meta))->withSteps(collect([
        new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', []),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    expect($store->pendingApprovalsFor($conversationId))->toBe([]);
});

test('a resume does not fold into a settled row once a newer plain turn follows it', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', 'Old.', [assistantStep()], []);
    insertAssistantTurn($conversationId, 'plain-2', 'Hi.', [assistantStep()]);

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        '',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = new AgentResponse('invocation-id', 'Done.', new TextUsage, new Meta);
    $response->steps = collect([new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [])]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    expect($messageId)->not->toBeIn(['paused-1', 'plain-2'])
        ->and(DB::table('agent_conversation_messages')->where('id', 'paused-1')->value('content'))->toBe('Old.');
});

test('it replays a completed multi-step turn with each result answering its own step', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Done.', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1']],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a', 'result_id' => 'result-1']],
            content: 'Reading a first.',
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'Deleted b']],
        ),
        assistantStep(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(5)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'Reading a first.'])
            ->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-1', 'resultId' => 'result-1']),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)
            ->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'call-1', 'resultId' => 'result-1']),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => ''])
            ->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-2']),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'call-2']),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'Done.'])->toolCalls->toBeEmpty(),
    );
});

test('it drops the unexecuted calls of a step-limited tail but keeps its text', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'I ran out of steps.', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]],
            [],
            [['type' => 'text', 'text' => 'I ran out of steps.'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], meta: ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-1']),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'I ran out of steps.', 'replayBlocks' => []])->toolCalls->toBeEmpty(),
    );
});

test('it replays every step of a paused turn with its replay blocks tagged by the provider that made them', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Read a, deleting b', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], ['call-2' => 'Destructive.'], ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['replayBlocksProvider' => 'anthropic'])->replayBlocks->toHaveCount(2),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'call-1']),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'Read a, deleting b', 'replayBlocksProvider' => 'anthropic'])->replayBlocks->toHaveCount(2),
    );
});

test('completing a turn stores no replay blocks and drops those of the paused rows it resumed', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
    ], [], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(new ToolUsingAgent, '', [], Mockery::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', 'Deleted b.', new TextUsage, new Meta('anthropic')))->withSteps(collect([
        new Step('Deleted b.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'text', 'text' => 'Deleted b.']]),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $blocks = DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->pluck('replay_blocks'));

    expect($blocks->all())->toBe([[], []])
        ->and(DB::table('agent_conversation_messages')->where('id', 'message-1')->value('status'))->toBe('completed');
});

test('a turn that pauses again keeps the replay blocks of the rows it resumed', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
    ], [], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(new ToolUsingAgent, '', [], Mockery::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', '', new TextUsage, new Meta('anthropic')))
        ->withSteps(collect([
            new Step('', [new ToolCall('call-2', 'DeleteFile', ['path' => 'c'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']]),
        ]))
        ->withPendingApprovals(collect([new PendingApproval('call-2', 'DeleteFile', ['path' => 'c'], 'Deletes a file')]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $blocks = DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('steps')
        ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->pluck('replay_blocks'));

    expect($blocks->all())->toEqualCanonicalizing([
        [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
    ])->and(DB::table('agent_conversation_messages')->where('id', 'message-1')->value('status'))->toBe('paused');
});

test('a resume folds into the paused row holding its decided call rather than the newest pause', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    insertAssistantTurn($conversationId, 'paused-1', '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'deleted']]),
    ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic']);

    insertAssistantTurn($conversationId, 'paused-2', '', [
        assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
    ], ['call-2' => 'Deletes b file'], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(new ToolUsingAgent, '', [], Mockery::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', 'Deleted a.', new TextUsage, new Meta('anthropic')))->withSteps(collect([
        new Step('Deleted a.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', []),
    ]));

    expect($store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response))->toBe('paused-1');

    $rows = DB::table('agent_conversation_messages')->where('role', 'assistant')->get()->keyBy('id');

    expect($rows['paused-1']->content)->toBe('Deleted a.')
        ->and($rows['paused-1']->status)->toBe('completed')
        ->and($rows['paused-2']->content)->toBe('')
        ->and($rows['paused-2']->status)->toBe('paused');
});

test('it skips a step that has nothing left to say once its unexecuted calls are dropped', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
        assistantStep([['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]]),
    ], meta: ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(2)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toolCalls->toHaveCount(1),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class),
    );
});

test('it replays a multi-step pause with each step carrying its own replay blocks', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Let me delete b too', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            [],
            [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], ['call-2' => null], ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject([
            'replayBlocks' => [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            'replayBlocksProvider' => 'anthropic',
        ]),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'call-1']),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject([
            'content' => 'Let me delete b too',
            'replayBlocks' => [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
            'replayBlocksProvider' => 'anthropic',
        ])->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-2']),
    );
});

test('it keeps an executed call and a pending call together on a mixed pause step', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Let me delete b too', [
        assistantStep(
            [
                ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']],
                ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']],
            ],
            [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a']],
        ),
    ], ['call-2' => null]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(2)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'Let me delete b too'])
            ->toolCalls->toHaveCount(2)->sequence(fn ($call) => $call->id->toBe('call-1'), fn ($call) => $call->id->toBe('call-2')),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'call-1']),
    );
});

test('it writes the steps of a paused turn with their replay blocks and keeps replay state out of meta', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Delete config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Let me think about that', new TextUsage, new Meta('anthropic')))
        ->withSteps(collect([
            new Step('', [new ToolCall('call-0', 'ReadFile', ['path' => 'a'])], [new ToolResult('call-0', 'ReadFile', ['path' => 'a'], 'contents')], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'tool_use', 'id' => 'call-0']]),
            new Step('Let me think about that', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]))
        ->withPendingApprovals(collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    expect($record->steps)->json()->toHaveCount(2)->sequence(
        fn ($step) => $step->toMatchArray(['content' => '', 'replay_blocks' => [['type' => 'tool_use', 'id' => 'call-0']]])
            ->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call-0', 'result' => 'contents']),
        fn ($step) => $step->toMatchArray(['content' => 'Let me think about that', 'replay_blocks' => [['type' => 'thinking', 'signature' => 'sig-1']]])
            ->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call-1'])->each->not->toHaveKey('result'),
    )
        ->and($record->steps)->json()->{'0'}->tool_calls->toHaveCount(1)
        ->and($record->meta)->json()->toBe(['provider' => 'anthropic', 'model' => null, 'citations' => []])
        ->and($record->steps)->json()->{'1'}->tool_calls->{'0'}->approval_reason->toBe('Deletes a file')
        ->and($record->status)->toBe('paused');
});

test('it writes the steps a paused stream carried on its approval request', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Delete config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collect([
        new ToolApprovalRequest('event-1', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]), 0, collect([
            new Step('', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]),
        ])),
    ]), new Meta);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

    expect($steps)->json()->toHaveCount(1)->{'0'}->toMatchArray(['replay_blocks' => [['type' => 'thinking', 'signature' => 'sig-1']]])
        ->and($steps)->json()->{'0'}->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call-1'])->each->not->toHaveKey('result');
});

test('it writes the steps a completed stream carried on its stream end', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Read config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $call = new ToolCall('call-1', 'ReadFile', ['path' => 'config/app.php']);

    $response = new StreamedAgentResponse('invocation-id', collect([
        new TextDelta('event-1', 'message-1', 'Done.', 0),
        new StreamEnd('event-2', 'stop', new TextUsage, 0, collect([
            new Step('', [$call], [new ToolResult('call-1', 'ReadFile', ['path' => 'config/app.php'], 'contents')], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'tool_use', 'id' => 'call-1']]),
            new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [['type' => 'text', 'text' => 'Done.']]),
        ])),
    ]), new Meta);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

    expect($steps)->json()->toHaveCount(2)->sequence(
        fn ($step) => $step->toMatchArray(['replay_blocks' => []])
            ->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call-1', 'result' => 'contents']),
        fn ($step) => $step->toMatchArray(['tool_calls' => [], 'replay_blocks' => []]),
    );
});

test('storing approval results for a conversation with no paused row throws', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);
})->throws(ApprovalMismatchException::class, 'The approval results do not match a paused conversation turn.');

test('a mismatch against a paused row carries the approvals that are actually pending', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
            ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'y']],
        ]),
    ], ['call-1' => 'Destructive operation.']);

    try {
        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-9', 'delete_file', ['path' => 'x'], 'Deleted x'),
        ]);

        $this->fail('Expected an approval mismatch.');
    } catch (ApprovalMismatchException $e) {
        expect($e->pendingApprovals->map->toArray()->all())->toBe([
            ['id' => 'call-1', 'tool' => 'delete_file', 'arguments' => ['path' => 'x'], 'reason' => 'Destructive operation.'],
        ]);
    }
});

test('resolving approval results does not require the resolver to be the paused turn\'s participant', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
    ], ['call-1' => 'Deletes x']);

    DB::table('agent_conversation_messages')->where('id', 'message-1')->update(['participant_id' => 2]);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    $row = DB::table('agent_conversation_messages')->where('id', 'message-1')->first();

    expect($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($row->steps)->json()->{'0'}->tool_calls->{'0'}->toMatchArray(['id' => 'call-1', 'result' => 'Deleted x']);
});

test('resolving approval results writes each outcome into the step that made the call', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep(
            [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
        ),
        assistantStep([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y']],
        ]),
    ], ['call-1' => 'Deletes x', 'call-2' => 'Deletes y']);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    $partial = $store->pendingApprovalsFor($conversationId);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'The user rejected this tool call.', denied: true),
    ]);

    $row = DB::table('agent_conversation_messages')->where('id', 'message-1')->first();

    expect($partial)->toHaveCount(1)->{'0'}->toMatchObject(['id' => 'call-2', 'reason' => 'Deletes y'])
        ->and($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($row->steps)->json()->sequence(
            fn ($step) => $step->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'call-0']),
            fn ($step) => $step->tool_calls->toHaveCount(2)->sequence(
                fn ($toolCall) => $toolCall->toMatchArray(['id' => 'call-1'])->not->toHaveKey('denied'),
                fn ($toolCall) => $toolCall->toMatchArray(['id' => 'call-2', 'denied' => true]),
            ),
        );
});

test('resolving an edited approval records the arguments the tool actually ran with', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
    ], ['call-1' => 'Deletes x']);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'y'], 'Deleted y'),
    ]);

    $steps = DB::table('agent_conversation_messages')->where('id', 'message-1')->value('steps');

    expect($steps)->json()->{'0'}->tool_calls->toHaveCount(1)->each->toMatchArray([
        'id' => 'call-1',
        'arguments' => ['path' => 'y'],
        'result' => 'Deleted y',
    ]);
});

test('it replays a resumed pause as the paused call, its result, then the resume turn', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']]]),
    ], ['call-1' => null]);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'a'], 'Deleted a'),
    ]);

    insertAssistantTurn($conversationId, 'message-2', 'Let me delete b too', [
        assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
    ], ['call-2' => null]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)->sequence(
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-1']),
        fn ($message) => $message->toBeInstanceOf(ToolResultMessage::class)->toolResults->toHaveCount(1)->each->toMatchObject(['result' => 'Deleted a']),
        fn ($message) => $message->toBeInstanceOf(AssistantMessage::class)->toMatchObject(['content' => 'Let me delete b too'])
            ->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'call-2']),
    );
});

test('it still rehydrates reasoning encrypted content stored on legacy tool calls', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Reasoning conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Looking that up.', [
        assistantStep(
            [[
                'id' => 'call-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'reasoning_id' => 'rs_1',
                'reasoning_summary' => [],
                'reasoning_encrypted_content' => 'enc-blob-1',
            ]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBe('rs_1')
        ->reasoningEncryptedContent->toBe('enc-blob-1');
});

test('user messages with stored attachments are rehydrated as UserMessage', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
        ]),
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content)->toBe('Describe this image.')
        ->and($messages[0]->attachments)->toHaveCount(1)
        ->and($messages[0]->attachments->first())->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments->first()->url)->toBe('https://example.com/photo.jpg');
});

test('user messages with multiple attachment types are all rehydrated', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Multi-attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Analyze these files.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
            ['type' => 'stored-document', 'path' => 'docs/report.pdf', 'disk' => 'local', 'name' => 'report.pdf'],
        ]),
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->attachments)->toHaveCount(2)
        ->and($messages[0]->attachments[0])->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments[1])->toBeInstanceOf(StoredDocument::class)
        ->and($messages[0]->attachments[1]->path)->toBe('docs/report.pdf');
});

test('user messages with no attachments are returned as plain Message', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Plain conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Hello.',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0])->toBeInstanceOf(Message::class)
        ->and($messages[0])->not->toBeInstanceOf(UserMessage::class);
});

test('malformed stored attachment JSON fails loudly', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Malformed attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode(['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg']),
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Stored conversation attachments must be a JSON array.');
});

test('malformed known stored attachments fail loudly', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Malformed attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'mime' => 'image/jpeg'],
        ]),
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Cannot reconstruct [remote-image] attachment because [url] is missing or invalid.');
});

test('it scopes conversations by participant type so shared ids no longer collide', function (): void {
    $store = new DatabaseConversationStore;

    $user = new class
    {
        public int $id = 1;

        public function getMorphClass(): string
        {
            return 'user';
        }
    };

    $admin = new class
    {
        public int $id = 1;

        public function getMorphClass(): string
        {
            return 'admin';
        }
    };

    $userConversation = $store->storeConversation('user', $user->id, 'User chat');
    $adminConversation = $store->storeConversation('admin', $admin->id, 'Admin chat');

    $insertMessage = fn (string $id, string $conversationId, string $participantType) => DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => $participantType,
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Hello',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insertMessage('message-user', $userConversation, 'user');
    $insertMessage('message-admin', $adminConversation, 'admin');

    // Despite sharing id 1, each participant only resolves its own conversation...
    expect($store->latestConversationId('user', $user->id, ToolUsingAgent::class))->toBe($userConversation)
        ->and($store->latestConversationId('admin', $admin->id, ToolUsingAgent::class))->toBe($adminConversation)
        ->and($userConversation)->not->toBe($adminConversation);
});

test('it records the reasoning a streamed turn produced onto the turn steps', function (): void {
    Config::set('ai.conversations.generate_title', false);

    $chunk = fn (array $delta, ?string $finishReason = null): string => 'data: '.json_encode([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion.chunk',
        'model' => 'deepseek-reasoner',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ]);

    Http::fake(['api.deepseek.com/*' => Http::response(
        body: implode("\n\n", [
            $chunk(['role' => 'assistant', 'reasoning_content' => 'They want ']),
            $chunk(['reasoning_content' => 'the temperature.']),
            $chunk(['content' => 'It is 12°C.']),
            $chunk([], 'stop'),
            'data: [DONE]',
        ])."\n\n",
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $response = (new RememberingAssistantAgent)
        ->forUser((object) ['id' => 1])
        ->stream('How cold is it?', provider: 'deepseek', model: 'deepseek-reasoner');

    foreach ($response as $event) {
        //
    }

    $record = DB::table('agent_conversation_messages')
        ->where('conversation_id', $response->conversationId)
        ->where('role', 'assistant')
        ->first();

    expect(json_decode((string) $record->steps, true))
        ->toHaveCount(1)
        ->{'0'}->toMatchArray(['content' => 'It is 12°C.', 'reasoning' => 'They want the temperature.'])
        ->and(json_decode((string) $record->meta, true))->not->toHaveKey('reasoning');
});

test('it records the reasoning a prompted turn produced onto the turn steps', function (): void {
    Config::set('ai.conversations.generate_title', false);

    Http::fake(['api.deepseek.com/*' => Http::response([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-reasoner',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'reasoning_content' => 'They want the temperature.',
                'content' => 'It is 12°C.',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = (new RememberingAssistantAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('How cold is it?', provider: 'deepseek', model: 'deepseek-reasoner');

    $record = DB::table('agent_conversation_messages')
        ->where('conversation_id', $response->conversationId)
        ->where('role', 'assistant')
        ->first();

    expect(json_decode((string) $record->steps, true))
        ->toHaveCount(1)
        ->{'0'}->toHaveKey('reasoning', 'They want the temperature.')
        ->and(json_decode((string) $record->meta, true))->not->toHaveKey('reasoning');
});

test('it records no reasoning on the turn steps when the model did not reason', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Quiet conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'How cold is it?',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collect([
        new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
    ]), new Meta);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    expect(json_decode((string) $record->steps, true))->{'0'}->toHaveKey('reasoning', '');
});

test('it records the sources a streamed turn cited into the message meta', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Researched conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'What does Laravel MCP do?',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collect([
        new TextDelta(uniqid(), 'message-1', 'Laravel MCP ships an MCP server.', time()),
        new CitationEvent(uniqid(), 'message-1', new UrlCitation('https://laravel.com/docs/mcp', 'Laravel MCP'), time()),
        new CitationEvent(uniqid(), 'message-1', new UrlCitation('https://laravel.com/docs/mcp', 'Laravel MCP'), time()),
    ]), new Meta);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    // Every mention is stored, matching what a generated turn records for the same answer...
    expect(json_decode((string) $record->meta, true)['citations'])->toBe([
        ['url' => 'https://laravel.com/docs/mcp', 'title' => 'Laravel MCP', 'start_index' => null, 'end_index' => null],
        ['url' => 'https://laravel.com/docs/mcp', 'title' => 'Laravel MCP', 'start_index' => null, 'end_index' => null],
    ]);
});

test('it stores no sources when a streamed turn cited nothing', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Unresearched conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'How cold is it?',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collect([
        new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
    ]), new Meta);

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    expect(json_decode((string) $record->meta, true)['citations'])->toBe([]);
});

test('it stores a user message from an agent class and a user message', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

    $messageId = $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage('Check my order status.'));

    $record = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

    expect($record->conversation_id)->toBe($conversationId)
        ->and($record->agent)->toBe(ToolUsingAgent::class)
        ->and($record->role)->toBe('user')
        ->and($record->content)->toBe('Check my order status.')
        ->and($record->attachments)->toBe('[]');
});

test('it stores the attachments a user message carries', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

    $messageId = $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage(
        'What is in this?',
        [new RemoteImage('https://example.com/order.png')],
    ));

    $attachments = json_decode((string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('attachments'), true);

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]['url'])->toBe('https://example.com/order.png');
});

test('it touches the conversation when a user message is stored', function (): void {
    $this->freezeTime();

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

    DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => now()->subDay()]);

    $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage('Check my order status.'));

    expect(DB::table('agent_conversations')->where('id', $conversationId)->value('updated_at'))
        ->toBe(now()->toDateTimeString());
});

function createConversationSchema(?string $connection = null): void
{
    $schema = Schema::connection($connection);

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

    $schema->create($conversationsTable, function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('participant_type');
        $table->string('participant_id');
        $table->string('title');
        $table->timestamps();
    });

    $schema->create($messagesTable, function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->string('participant_type');
        $table->string('participant_id');
        $table->string('agent');
        $table->string('role', 25);
        $table->text('content');
        $table->text('attachments');
        $table->text('steps');
        $table->text('usage');
        $table->text('meta');
        $table->string('status', 25);
        $table->timestamps();
    });
}

/** @return array<string, mixed> */
function storedConversationMessageAttributes(string $id, string $conversationId, string $content): array
{
    return [
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => $content,
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $toolCalls
 * @param  list<array<string, mixed>>  $toolResults
 * @param  list<array<string, mixed>>  $replayBlocks
 * @return array<string, mixed>
 */
function assistantStep(array $toolCalls = [], array $toolResults = [], array $replayBlocks = [], string $content = ''): array
{
    $results = collect($toolResults)->keyBy('id');

    return [
        'content' => $content,
        'tool_calls' => array_map(fn (array $call): array => [
            ...$call,
            ...Arr::only($results[$call['id']] ?? [], ['result', 'denied', 'failed']),
        ], $toolCalls),
        'replay_blocks' => $replayBlocks,
    ];
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, string|null>|null  $pending  Reasons keyed by the tool call IDs still awaiting a decision, or null when the turn never paused
 * @param  array<string, mixed>  $meta
 */
function insertAssistantTurn(string $conversationId, string $id, string $content, array $steps, ?array $pending = null, array $meta = []): void
{
    if ($steps !== [] && $steps[array_key_last($steps)]['content'] === '') {
        $steps[array_key_last($steps)]['content'] = $content;
    }

    $steps = array_map(fn (array $step): array => [...$step, 'tool_calls' => array_map(
        fn (array $toolCall) => array_key_exists($toolCall['id'], $pending ?? []) ? [...$toolCall, 'approval_reason' => $pending[$toolCall['id']]] : $toolCall,
        $step['tool_calls'],
    )], $steps);

    DB::table('agent_conversation_messages')->insert([
        ...storedConversationMessageAttributes($id, $conversationId, $content),
        'role' => 'assistant',
        'steps' => json_encode($steps),
        'meta' => json_encode($meta),
        'status' => $pending === null ? MessageStatus::Completed : MessageStatus::Paused,
    ]);
}

/** @param  list<string>  $ids */
function insertStoredConversationMessages(string $conversationId, array $ids): void
{
    DB::table('agent_conversation_messages')->insert(
        collect($ids)->map(fn (string $id): array => storedConversationMessageAttributes($id, $conversationId, "Content for {$id}"))->all()
    );
}

/** @param  list<array<string, mixed>>  $toolCalls */
function insertPausedConversationTurn(string $conversationId, string $id, array $toolCalls, array $pending): void
{
    insertAssistantTurn($conversationId, $id, 'Waiting on you.', [assistantStep($toolCalls)], $pending);
}

test('provider reasoning state is kept on the step replay blocks rather than copied onto each tool call', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Reasoning conversation');
    $prompt = new AgentPrompt(new ToolUsingAgent, 'Look it up', [], Mockery::mock(TextProvider::class), 'test-model');

    $reasoningItem = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'];

    $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage, new Meta('openai', 'gpt-5')))
        ->withSteps(collect([new Step(
            'Found it.',
            [new ToolCall('fc_1', 'ReadFile', ['path' => 'a'], 'call_1', 'rs_1', [], 'enc-blob-1')],
            [new ToolResult('fc_1', 'ReadFile', ['path' => 'a'], 'contents', 'call_1')],
            FinishReason::Stop,
            new TextUsage,
            new Meta('openai', 'gpt-5'),
            '',
            [$reasoningItem, ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ReadFile', 'arguments' => '{"path":"a"}']],
        )]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $step = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true)[0];

    expect($step['tool_calls'][0])->toBe([
        'id' => 'fc_1',
        'name' => 'ReadFile',
        'arguments' => ['path' => 'a'],
        'result_id' => 'call_1',
        'result' => 'contents',
    ])->and($step['replay_blocks'])->toBe([]);
});

test('a step that dropped an unanswered call replays generically so no raw block names a call without a result', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    insertAssistantTurn($conversationId, 'message-1', 'Read a', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']], ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'b']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], meta: ['provider' => 'anthropic']);

    $message = $store->getLatestConversationMessages($conversationId, 10)->first();

    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->and($message->replayBlocks)->toBe([])
        ->and($message->toolCalls->pluck('id')->all())->toBe(['call-1']);
});

test('provider tool calls are stored per step and exposed on the stored message and model', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', 1, 'Search conversation');
    $prompt = new AgentPrompt(new ToolUsingAgent, 'Search', [], Mockery::mock(TextProvider::class), 'test-model');

    $search = new ProviderToolCall('ws-1', 'web_search_call', ['action' => ['query' => 'laravel ai']]);
    $execution = new ProviderToolCall('ce-1', 'code_interpreter_call', ['code' => 'print(1)']);

    $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage, new Meta('openai', 'gpt-5')))
        ->withSteps(collect([
            new Step('', [], [], FinishReason::Stop, new TextUsage, new Meta('openai', 'gpt-5'), '', [], [$search]),
            new Step('Found it.', [], [], FinishReason::Stop, new TextUsage, new Meta('openai', 'gpt-5'), '', [], [$execution]),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    $steps = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true);

    expect($steps[0]['provider_tool_calls'])->toBe([$search->toArray()])
        ->and($steps[1]['provider_tool_calls'])->toBe([$execution->toArray()])
        ->and($store->paginateConversationMessages($conversationId, 1)->items()[0]->providerToolCalls())->toBe([$search->toArray(), $execution->toArray()])
        ->and(ConversationMessage::query()->where('role', 'assistant')->first()->provider_tool_calls)->toBe([$search->toArray(), $execution->toArray()]);
});
