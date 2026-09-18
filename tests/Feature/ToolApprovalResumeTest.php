<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\RememberingMultiStepApprovableAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

test('a remembered agent pauses for approval, persists the tool_use, and resumes from history when approved', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals)->toHaveCount(1)
        ->and($paused->pendingApprovals[0]->id)->toBe('toolu_1')
        ->and($paused->conversationId)->not->toBeNull();

    $assistantRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect(json_decode($assistantRow->tool_calls, true))->toHaveCount(1)
        ->and(json_decode($assistantRow->tool_calls, true)[0]['id'])->toBe('toolu_1')
        ->and(json_decode($assistantRow->tool_results, true))->toBeEmpty();

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults[0]->result)->toBe('72019');
});

test('an ownerless remembered agent pauses for approval and resumes without a participant', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $paused = (new RememberingApprovableAgent)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals)->toHaveCount(1)
        ->and($paused->pendingApprovals[0]->id)->toBe('toolu_1')
        ->and($paused->conversationId)->not->toBeNull()
        ->and($paused->conversationUser)->toBeNull();

    $assistantRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantRow->participant_type)->toBeNull()
        ->and($assistantRow->participant_id)->toBeNull()
        ->and(json_decode($assistantRow->tool_calls, true)[0]['id'])->toBe('toolu_1')
        ->and(json_decode($assistantRow->tool_results, true))->toBeEmpty();

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults[0]->result)->toBe('72019');
});

test('a resumed approval replays the paused turn provider content blocks', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'Deciding whether to call the tool.',
                        'signature' => 'signature-1',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

    $assistantTurn = collect($resumeMessages)->firstWhere('role', 'assistant');

    expect($assistantTurn['content'][0]['type'])->toBe('thinking')
        ->and($assistantTurn['content'][0]['signature'])->toBe('signature-1')
        ->and(collect($assistantTurn['content'])->firstWhere('type', 'tool_use')['id'])->toBe('toolu_1');
});

test('a resume after a multi-step pause replays each step with its own signed thinking and answers its tool_use', function () {
    Config::set('ai.conversations.generate_title', false);

    $message = fn (array $content, string $stopReason) => Http::response([
        'id' => 'msg',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => $content,
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $message([
                ['type' => 'thinking', 'thinking' => 'First the fixed number.', 'signature' => 'signature-1'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
            ], 'tool_use'),
            $message([
                ['type' => 'thinking', 'thinking' => 'Now the gated number.', 'signature' => 'signature-2'],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
            ], 'tool_use'),
            $message([['type' => 'text', 'text' => 'Both numbers are 72019.']], 'end_turn'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Generate both numbers', provider: 'anthropic');

    expect($paused->pendingApprovals->pluck('id')->all())->toBe(['toolu_2']);

    $resumed = (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

    $turns = collect($resumeMessages)->map(fn (array $message) => [
        $message['role'],
        collect($message['content'])->map(fn (array $block) => $block['tool_use_id'] ?? $block['id'] ?? $block['type'])->all(),
    ])->all();

    $signatures = collect($resumeMessages)
        ->flatMap(fn (array $message) => collect($message['content'])->pluck('signature')->filter())
        ->all();

    expect($resumed->text)->toBe('Both numbers are 72019.')
        ->and($turns)->toBe([
            ['user', ['text']],
            ['assistant', ['thinking', 'toolu_1']],
            ['user', ['toolu_1']],
            ['assistant', ['thinking', 'toolu_2']],
            ['user', ['toolu_2']],
        ])
        ->and($signatures)->toBe(['signature-1', 'signature-2']);
});

test('a streamed multi-step pause stores every step so the resume replays each one with its own blocks', function () {
    Config::set('ai.conversations.generate_title', false);

    $sse = fn (array $events) => Http::response(
        implode("\n\n", array_map(fn (array $event) => 'data: '.json_encode($event), $events))."\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    );

    $step = fn (string $signature, string $toolId, string $toolName) => $sse([
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Deciding.']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => $signature]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => $toolId, 'name' => $toolName, 'input' => (object) []]],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $step('signature-1', 'toolu_1', 'FixedNumberGenerator'),
            $step('signature-2', 'toolu_2', 'ApprovableNumberGenerator'),
            $sse([
                ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Both numbers are 72019.']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->stream('Generate both numbers', provider: 'anthropic');

    $paused->each(fn () => true);

    $meta = json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('meta'), true);

    expect(collect($meta['provider_steps'])->pluck('tool_call_ids')->all())->toBe([['toolu_1'], ['toolu_2']])
        ->and($meta['provider_steps'][0]['blocks'][0]['signature'])->toBe('signature-1')
        ->and($meta['provider_steps'][1]['blocks'][0]['signature'])->toBe('signature-2');

    $resumed = (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    $resumed->each(fn () => true);

    $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

    $turns = collect($resumeMessages)->map(fn (array $message) => [
        $message['role'],
        collect($message['content'])->map(fn (array $block) => $block['tool_use_id'] ?? $block['id'] ?? $block['type'])->all(),
    ])->all();

    expect($turns)->toBe([
        ['user', ['text']],
        ['assistant', ['thinking', 'toolu_1']],
        ['user', ['toolu_1']],
        ['assistant', ['thinking', 'toolu_2']],
        ['user', ['toolu_2']],
    ]);
});

test('a resume on a different provider falls back to the generic mapping instead of replaying foreign blocks', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'Deciding whether to call the tool.',
                        'signature' => 'signature-1',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    // Rewrite the paused row as if the pause had happened on a failover provider...
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->get()
        ->each(function ($row) {
            $meta = json_decode($row->meta, true);
            $meta['provider'] = 'openai';

            DB::table('agent_conversation_messages')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
        });

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

    $assistantTurn = collect($resumeMessages)->firstWhere('role', 'assistant');

    expect(collect($assistantTurn['content'])->firstWhere('type', 'thinking'))->toBeNull()
        ->and(collect($assistantTurn['content'])->firstWhere('type', 'tool_use')['id'])->toBe('toolu_1');
});

test('a resume that pauses again can itself be resumed', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_tool_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_2',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_3',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Both numbers generated.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate two numbers', provider: 'anthropic');

    $pausedAgain = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($pausedAgain->hasPendingApprovals())->toBeTrue()
        ->and($pausedAgain->pendingApprovals[0]->id)->toBe('toolu_2');

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('Both numbers generated.');
});

test('a plain prompt after an abandoned pause settles the dangling tool call', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hi there.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_3',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hi again.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    $reply = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt('Never mind, just say hi', provider: 'anthropic');

    expect($reply->text)->toBe('Hi there.');

    $messages = collect(Http::recorded())->last()[0]->data()['messages'];
    $toolResults = collect($messages)
        ->flatMap(fn (array $message) => is_array($message['content']) ? $message['content'] : [])
        ->filter(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'tool_result');

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults->first()['tool_use_id'])->toBe('toolu_1');

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt('Say hi once more', provider: 'anthropic');

    $messages = collect(collect(Http::recorded())->last()[0]->data()['messages'])->values();

    $toolUseIndex = $messages->search(fn (array $message) => collect(is_array($message['content']) ? $message['content'] : [])
        ->contains(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'tool_use' && $block['id'] === 'toolu_1'));

    $answeringBlocks = collect($messages[$toolUseIndex + 1]['content'] ?? [])
        ->filter(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'tool_result');

    expect($toolUseIndex)->not->toBeFalse()
        ->and($answeringBlocks->pluck('tool_use_id')->all())->toBe(['toolu_1']);
});

test('a streamed resume with mismatched decisions throws before the stream begins and releases the claim', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['bogus-id' => true]), provider: 'anthropic')
    )->toThrow(ApprovalMismatchException::class);

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->text)->toBe('The number is 72019.');
});

test('a valid streamed resume checks a gated tool call for approval exactly once', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    ApprovableNumberGenerator::$approvalChecks = 0;

    $response = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $response->each(fn () => true);

    expect(ApprovableNumberGenerator::$approvalChecks)->toBe(1);
});

test('a resume does not fail over to another provider and re-run the approved tool', function () {
    Config::set('ai.conversations.generate_title', false);

    config([
        'ai.providers.primary' => ['driver' => 'anthropic', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'anthropic', 'key' => 'test-key'],
    ]);

    ApprovableNumberGenerator::$invocations = 0;

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)
            ->push(status: 429)
            ->push(status: 429),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: ['primary', 'backup']);

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: ['primary', 'backup'])
    )->toThrow(RateLimitedException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('a successful resume records the approved result exactly once across history', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)
            ->push([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent)->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $recorded = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->pluck('tool_results')
        ->flatMap(fn ($results) => collect(json_decode($results, true))->pluck('id'))
        ->filter(fn ($id) => $id === 'toolu_1');

    expect($recorded)->toHaveCount(1);
});

test('a rejected resume stores and rehydrates the tool result as denied', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ApprovableNumberGenerator',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    $rejected = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => false]), provider: 'anthropic');

    expect($rejected->hasPendingApprovals())->toBeFalse();

    $assistantRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect(json_decode($assistantRow->tool_results, true)[0]['denied'])->toBeTrue();

    $store = new DatabaseConversationStore;
    $messages = $store->getLatestConversationMessages($paused->conversationId, 10);

    $toolResultMessage = $messages->first(fn ($message) => $message instanceof ToolResultMessage);

    expect($toolResultMessage->toolResults->first()->denied)->toBeTrue();
});

test('a resume that fails after the tool runs does not re-execute the tool on retry', function () {
    Config::set('ai.conversations.generate_title', false);

    ApprovableNumberGenerator::$invocations = 0;

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)
            ->push(status: 500),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
    )->toThrow(Exception::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
    )->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('a resume settles the paused row before the run writes a newer one', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ], [
                    'type' => 'tool_use',
                    'id' => 'toolu_2',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];
    $store = new DatabaseConversationStore;

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(collect($store->pendingApprovalsFor($paused->conversationId))->pluck('id')->all())
        ->toBe(['toolu_1', 'toolu_2']);

    $pausedRowId = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->latest('id')
        ->value('id');

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true, 'toolu_2' => true]), provider: 'anthropic');

    // Only the newest row is read for pending approvals, so a resume that settled some calls and left others behind on an older row would report a finished turn while the user still owed a decision...
    $pausedRow = DB::table('agent_conversation_messages')->where('id', $pausedRowId)->first();

    $newerRows = DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('id', '>', $pausedRowId)
        ->count();

    expect($newerRows)->toBeGreaterThan(0)
        ->and(json_decode($pausedRow->approval_state, true)['pending'])->toBe([])
        ->and($store->pendingApprovalsFor($paused->conversationId))->toBe([]);
});
