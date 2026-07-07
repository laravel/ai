<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
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
