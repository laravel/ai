<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

test('the replay state never reaches a serialized event', function (): void {
    $event = new ToolApprovalRequest('event-id', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collect([pausedStep()]));

    expect($event->toArray())->not->toHaveKey('steps')
        ->and($event->toArray()['type'])->toBe('tool_approval_request');
});

function pausedStep(): Step
{
    return new Step('', [], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]);
}
