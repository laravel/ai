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

test('a resume as a different user is rejected before the approved tool runs', function () {
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

    $owner = (object) ['id' => 1];
    $otherUser = (object) ['id' => 2];

    $paused = (new RememberingApprovableAgent)->forUser($owner)->prompt('Generate a number', provider: 'anthropic');

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $otherUser)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
    )->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(0);

    $pending = json_decode(DB::table('agent_conversation_messages')
        ->where('conversation_id', $paused->conversationId)
        ->where('role', 'assistant')
        ->value('approval_state'), true)['pending'] ?? [];

    expect($pending)->toHaveKey('toolu_1');

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $owner)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->text)->toBe('The number is 72019.')
        ->and(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('a resume without a user is rejected before the approved tool runs when the pause belongs to one', function () {
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
            ], 200),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn () => (new RememberingApprovableAgent)
        ->continue($paused->conversationId)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
    )->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(0);
});
