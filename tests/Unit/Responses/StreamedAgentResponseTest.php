<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

function streamedResponseFor(array $events): StreamedAgentResponse
{
    return new StreamedAgentResponse('invocation-id', collect($events), new Meta);
}

test('a paused stream exposes the replay state carried by the approval request', function (): void {
    $steps = [['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']]];

    $response = streamedResponseFor([
        new ToolApprovalRequest('e1', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, $steps, [['type' => 'thinking', 'signature' => 'sig-1']]),
    ]);

    expect($response->pausedSteps())->toBe($steps)
        ->and($response->pausedProviderContentBlocks())->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('a stream that completed without pausing exposes no replay state', function (): void {
    $response = streamedResponseFor([new StreamEnd('e1', 'stop', new TextUsage, 1)]);

    expect($response->pausedSteps())->toBe([])
        ->and($response->pausedProviderContentBlocks())->toBe([]);
});
