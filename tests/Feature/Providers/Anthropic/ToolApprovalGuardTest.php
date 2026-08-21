<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\StatelessApprovableAgent;
use Tests\Fixtures\Agents\StatelessMixedToolsAgent;
use Tests\Fixtures\Tools\SideEffectRecorder;

test('a gated tool on a non-conversational agent throws when it pauses', function () {
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

    (new StatelessApprovableAgent)->prompt('Generate a number', provider: 'anthropic');
})->throws(ApprovalNotResumableException::class);

test('a gated tool on a non-conversational agent throws before streaming a pause to the client', function () {
    StatelessApprovableAgent::fake([
        new ToolCall('toolu_1', 'ApprovableNumberGenerator', [], 'result-1'),
    ]);

    $stream = (new StatelessApprovableAgent)->stream('Generate a number');

    $events = [];
    $thrown = null;

    try {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    } catch (ApprovalNotResumableException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ApprovalNotResumableException::class)
        ->and(array_filter($events, fn ($event) => $event instanceof ToolApprovalRequest))->toBeEmpty();
});

test('a gated tool pauses instead of throwing when the client replays history, even on the first turn', function () {
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

    $paused = (new StatelessApprovableAgent)
        ->withMessages([])
        ->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals[0]->id)->toBe('toolu_1');
});

test('a gated tool on a conversational agent pauses ownerless instead of throwing', function () {
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

    $paused = (new RememberingApprovableAgent)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->conversationId)->not->toBeNull()
        ->and($paused->conversationUser)->toBeNull();
});

test('a non-resumable pause runs its step, then throws', function () {
    SideEffectRecorder::$invocations = 0;

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_side',
                    'name' => 'SideEffectRecorder',
                    'input' => (object) [],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_gated',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    // The pause is rejected because the agent cannot resume; its ungated companion runs first, as any step's would.
    expect(fn () => (new StatelessMixedToolsAgent)->prompt('Record and generate', provider: 'anthropic'))
        ->toThrow(ApprovalNotResumableException::class)
        ->and(SideEffectRecorder::$invocations)->toBe(1);
});
