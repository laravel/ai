<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

function streamedResponseFor(array $events): StreamedAgentResponse
{
    return new StreamedAgentResponse('invocation-id', collect($events), new Meta);
}

function streamedStep(string $text): Step
{
    return new Step($text, [], [], FinishReason::Stop, new Usage, new Meta, 'I thought about it.', [['type' => 'thinking', 'signature' => 'sig-1']]);
}

test('a paused stream exposes the steps carried by the approval request', function (): void {
    $response = streamedResponseFor([
        new ToolApprovalRequest('e1', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collect([streamedStep('')])),
    ]);

    expect($response->steps->first()->providerContentBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('a completed stream exposes the steps carried by the stream end', function (): void {
    $response = streamedResponseFor([new StreamEnd('e1', 'stop', new Usage, 1, collect([streamedStep('Done.')]))]);

    expect($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->text)->toBe('Done.')
        ->and($response->steps->first()->reasoning)->toBe('I thought about it.');
});

test('a stream carrying neither event exposes no steps', function (): void {
    expect(streamedResponseFor([])->steps)->toBeEmpty();
});
