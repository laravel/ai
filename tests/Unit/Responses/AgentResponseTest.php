<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

function agentResponseWithSteps(): AgentResponse
{
    return (new AgentResponse('invocation-id', '', new TextUsage, new Meta))
        ->withMessages(collect([
            new AssistantMessage('', collect([new ToolCall('call-0', 'ReadFile', [])]), [['type' => 'thinking', 'signature' => 'sig-0']]),
            new AssistantMessage('Done', collect([new ToolCall('call-1', 'DeleteFile', [])]), [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]));
}

test('a paused turn exposes the blocks and tool call ids of every assistant step', function (): void {
    $response = agentResponseWithSteps()
        ->withPendingApprovals(collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]));

    expect($response->pausedSteps())->toBe([
        ['blocks' => [['type' => 'thinking', 'signature' => 'sig-0']], 'tool_call_ids' => ['call-0']],
        ['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']],
    ])->and($response->pausedProviderContentBlocks())->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('a turn that completed without pausing exposes no replay state', function (): void {
    $response = agentResponseWithSteps();

    expect($response->pausedSteps())->toBe([])
        ->and($response->pausedProviderContentBlocks())->toBe([]);
});
