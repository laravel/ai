<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

function streamedResponseFor(array $events): StreamedAgentResponse
{
    return new StreamedAgentResponse('invocation-id', collect($events), new Meta);
}

test('provider steps come from the stream end of a completed turn', function (): void {
    $steps = [['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => []]];

    expect(streamedResponseFor([new StreamEnd('e1', 'stop', new Usage, 1, $steps)])->providerSteps())->toBe($steps);
});

test('provider steps come from the approval request of a paused turn', function (): void {
    $paused = [['blocks' => [['type' => 'thinking', 'signature' => 'paused']], 'tool_call_ids' => ['call-1']]];
    $ended = [['blocks' => [['type' => 'thinking', 'signature' => 'ended']], 'tool_call_ids' => []]];

    $response = streamedResponseFor([
        new ToolApprovalRequest('e1', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, $paused),
        new StreamEnd('e2', 'stop', new Usage, 1, $ended),
    ]);

    expect($response->providerSteps())->toBe($paused);
});

test('a stream that produced no provider blocks has no provider steps', function (): void {
    $response = streamedResponseFor([
        new StreamEnd('e1', 'stop', new Usage, 1, [['blocks' => [], 'tool_call_ids' => []]]),
    ]);

    expect($response->providerSteps())->toBe([]);
});

test('the deprecated paused accessors read the approval request', function (): void {
    $steps = [['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']]];

    $response = streamedResponseFor([
        new ToolApprovalRequest('e1', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, $steps, [['type' => 'thinking', 'signature' => 'sig-1']]),
    ]);

    expect($response->pausedSteps())->toBe($steps)
        ->and($response->pausedProviderContentBlocks())->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('the deprecated paused accessors stay empty on a completed stream', function (): void {
    $response = streamedResponseFor([
        new StreamEnd('e1', 'stop', new Usage, 1, [['blocks' => [['type' => 'thinking']], 'tool_call_ids' => []]]),
    ]);

    expect($response->pausedSteps())->toBe([])
        ->and($response->pausedProviderContentBlocks())->toBe([]);
});
