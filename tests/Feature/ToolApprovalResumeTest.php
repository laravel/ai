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

test('a resume of a pause on a later step replays every earlier step so no tool_result is orphaned', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Research first.', 'signature' => 'signature-1'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_tool_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Now the gated tool.', 'signature' => 'signature-2'],
                    ['type' => 'text', 'text' => 'Generating the approved number.'],
                    ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_3',
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

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Research, then generate', provider: 'anthropic');

    expect($paused->pendingApprovals->pluck('id')->all())->toBe(['toolu_2']);

    $resumed = (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    expect($resumed->text)->toBe('The number is 72019.');

    $messages = collect(Http::recorded())->last()[0]->data()['messages'];

    // Anthropic rejects the request unless every tool_result is answered by a tool_use in the message right before it...
    foreach ($messages as $index => $message) {
        $resultIds = collect($message['content'])->where('type', 'tool_result')->pluck('tool_use_id')->all();

        if ($resultIds === []) {
            continue;
        }

        $callIds = collect($messages[$index - 1]['content'] ?? [])->where('type', 'tool_use')->pluck('id')->all();

        expect(array_diff($resultIds, $callIds))->toBe([], "orphaned tool_result in message {$index}");
    }

    $assistantTurns = collect($messages)->where('role', 'assistant')->values();

    // Each step replays with its own signed thinking block, in the order it happened...
    expect($assistantTurns)->toHaveCount(2)
        ->and($assistantTurns[0]['content'][0]['signature'])->toBe('signature-1')
        ->and(collect($assistantTurns[0]['content'])->firstWhere('type', 'tool_use')['id'])->toBe('toolu_1')
        ->and($assistantTurns[1]['content'][0]['signature'])->toBe('signature-2')
        ->and(collect($assistantTurns[1]['content'])->firstWhere('type', 'tool_use')['id'])->toBe('toolu_2');
});

function assertToolCallsArePaired(array $messages, string $label): void
{
    foreach ($messages as $index => $message) {
        $resultIds = collect($message['content'])->where('type', 'tool_result')->pluck('tool_use_id')->all();

        if ($resultIds === []) {
            continue;
        }

        $callIds = collect($messages[$index - 1]['content'] ?? [])->where('type', 'tool_use')->pluck('id')->all();

        expect(array_diff($resultIds, $callIds))->toBe([], "{$label}: orphaned tool_result in message {$index}");
    }

    // And the reverse: no tool_use may go unanswered except in the final assistant turn.
    foreach ($messages as $index => $message) {
        if (($message['role'] ?? '') !== 'assistant' || $index === count($messages) - 1) {
            continue;
        }

        $callIds = collect($message['content'])->where('type', 'tool_use')->pluck('id')->all();

        if ($callIds === []) {
            continue;
        }

        $resultIds = collect($messages[$index + 1]['content'] ?? [])->where('type', 'tool_result')->pluck('tool_use_id')->all();

        expect(array_diff($callIds, $resultIds))->toBe([], "{$label}: unanswered tool_use in message {$index}");
    }
}

function anthropicToolStep(string $id, string $tool, string $callId, string $signature): array
{
    return [
        'id' => $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'thinking', 'signature' => $signature],
            ['type' => 'tool_use', 'id' => $callId, 'name' => $tool, 'input' => (object) []],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function anthropicFinalStep(string $id): array
{
    return [
        'id' => $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => 'Done.']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

test('a resume that pauses again on a later step resumes cleanly', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            anthropicToolStep('m1', 'FixedNumberGenerator', 'toolu_1', 'sig-1'),
            anthropicToolStep('m2', 'ApprovableNumberGenerator', 'toolu_2', 'sig-2'),
            // Resume: an auto step, then a second pause.
            anthropicToolStep('m3', 'FixedNumberGenerator', 'toolu_3', 'sig-3'),
            anthropicToolStep('m4', 'ApprovableNumberGenerator', 'toolu_4', 'sig-4'),
            anthropicFinalStep('m5'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Go', provider: 'anthropic');
    expect($paused->pendingApprovals->pluck('id')->all())->toBe(['toolu_2']);

    $repaused = (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    expect($repaused->pendingApprovals->pluck('id')->all())->toBe(['toolu_4']);

    assertToolCallsArePaired(collect(Http::recorded())->last()[0]->data()['messages'], 'second pause request');

    $final = (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_4' => true]), provider: 'anthropic');

    expect($final->text)->toBe('Done.');

    assertToolCallsArePaired(collect(Http::recorded())->last()[0]->data()['messages'], 'second resume request');
});

test('an abandoned multi-step pause settles when the conversation continues', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            anthropicToolStep('m1', 'FixedNumberGenerator', 'toolu_1', 'sig-1'),
            anthropicToolStep('m2', 'ApprovableNumberGenerator', 'toolu_2', 'sig-2'),
            anthropicFinalStep('m3'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Go', provider: 'anthropic');
    expect($paused->hasPendingApprovals())->toBeTrue();

    (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt('Never mind, something else', provider: 'anthropic');

    assertToolCallsArePaired(collect(Http::recorded())->last()[0]->data()['messages'], 'abandoned pause');
});

test('a resolved pause keeps replaying its steps once the approval has landed', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            anthropicToolStep('m1', 'FixedNumberGenerator', 'toolu_1', 'sig-1'),
            anthropicToolStep('m2', 'ApprovableNumberGenerator', 'toolu_2', 'sig-2'),
            anthropicFinalStep('m3'),
            anthropicFinalStep('m4'),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Go', provider: 'anthropic');

    (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    // A fresh turn: the resolved row still describes itself step by step rather than collapsing into one parallel call...
    (new RememberingMultiStepApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt('And now something else', provider: 'anthropic');

    $messages = collect(Http::recorded())->last()[0]->data()['messages'];

    assertToolCallsArePaired($messages, 'resolved turn replay');

    $assistantTurns = collect($messages)->where('role', 'assistant')->values();

    expect($assistantTurns)->toHaveCount(3)
        ->and($assistantTurns[0]['content'][0]['signature'])->toBe('sig-1')
        ->and(collect($assistantTurns[0]['content'])->where('type', 'tool_use')->pluck('id')->all())->toBe(['toolu_1'])
        ->and($assistantTurns[1]['content'][0]['signature'])->toBe('sig-2')
        ->and(collect($assistantTurns[1]['content'])->where('type', 'tool_use')->pluck('id')->all())->toBe(['toolu_2'])
        ->and($assistantTurns[2]['content'][0]['text'])->toBe('Done.');
});
