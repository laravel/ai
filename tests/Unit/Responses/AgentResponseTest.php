<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

function agentResponseWithSteps(): AgentResponse
{
    return (new AgentResponse('invocation-id', '', new Usage, new Meta))
        ->withMessages(collect([
            new AssistantMessage('', collect([new ToolCall('call-0', 'ReadFile', [])]), [['type' => 'thinking', 'signature' => 'sig-0']]),
            new AssistantMessage('Done', collect([new ToolCall('call-1', 'DeleteFile', [])]), [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]));
}

test('provider steps carry the blocks and tool call ids of every assistant step', function (): void {
    expect(agentResponseWithSteps()->providerSteps())->toBe([
        ['blocks' => [['type' => 'thinking', 'signature' => 'sig-0']], 'tool_call_ids' => ['call-0']],
        ['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']],
    ]);
});

test('a turn that produced no provider blocks has no provider steps', function (): void {
    $response = (new AgentResponse('invocation-id', 'Hello', new Usage, new Meta))
        ->withMessages(collect([new AssistantMessage('Hello')]));

    expect($response->providerSteps())->toBe([]);
});

test('the deprecated paused accessors return the turn state only while approvals are pending', function (): void {
    $response = agentResponseWithSteps();

    expect($response->pausedSteps())->toBe([])
        ->and($response->pausedProviderContentBlocks())->toBe([]);

    $response->withPendingApprovals(collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]));

    expect($response->pausedSteps())->toBe($response->providerSteps())
        ->and($response->pausedProviderContentBlocks())->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});
