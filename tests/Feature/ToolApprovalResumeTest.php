<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\ToolApproval;
use Tests\Fixtures\Agents\RememberingApprovableAgent;

test('a remembered agent pauses for approval, persists the tool_use, and resumes from history when approved', function () {
    // Title generation makes its own provider call and would consume a faked response, so disable it for this flow.
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

    expect($paused->awaitingApproval())->toBeTrue()
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
        ->and(json_decode($assistantRow->tool_results, true))->toBe([]);

    $resumed = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(ToolApproval::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->awaitingApproval())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults[0]->result)->toBe('72019');
});