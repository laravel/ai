<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

test('an approval request carries the paused turn replay state', function (): void {
    $steps = collect([pausedStep()]);

    $event = new ToolApprovalRequest('event-id', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, $steps);

    expect($event->steps)->toBe($steps);
});

test('the replay state is optional and defaults to empty', function (): void {
    expect((new ToolApprovalRequest('event-id', collect(), 1))->steps)->toBeEmpty();
});

test('the replay state never reaches a serialized event', function (): void {
    $event = new ToolApprovalRequest('event-id', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collect([pausedStep()]));

    expect($event->toArray())->not->toHaveKey('steps')
        ->and($event->toArray()['type'])->toBe('tool_approval_request');
});

function pausedStep(): Step
{
    return new Step('', [], [], FinishReason::ToolCalls, new Usage, new Meta, providerContentBlocks: [['type' => 'thinking', 'signature' => 'sig-1']]);
}
