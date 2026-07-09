<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Agents\StatelessApprovableAgent;

test('a gated tool on a non-conversational agent throws at pause time', function () {
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

test('a gated tool on a conversational agent with no conversation participant throws at pause time', function () {
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

    (new RememberingApprovableAgent)->prompt('Generate a number', provider: 'anthropic');
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
