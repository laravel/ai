<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

test('an approval request carries the paused turn replay state', function (): void {
    $steps = [['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']]];

    $event = new ToolApprovalRequest(
        'event-id',
        collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]),
        1,
        $steps,
        [['type' => 'thinking', 'signature' => 'sig-1']],
    );

    expect($event->steps)->toBe($steps)
        ->and($event->providerContentBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('the replay state is optional and defaults to empty', function (): void {
    $event = new ToolApprovalRequest('event-id', collect(), 1);

    expect($event->steps)->toBe([])
        ->and($event->providerContentBlocks)->toBe([]);
});

test('the replay state never reaches a serialized event', function (): void {
    $event = new ToolApprovalRequest(
        'event-id',
        collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]),
        1,
        [['blocks' => [['type' => 'thinking']], 'tool_call_ids' => ['call-1']]],
        [['type' => 'thinking']],
    );

    expect($event->toArray())->not->toHaveKey('steps')
        ->and($event->toArray())->not->toHaveKey('provider_content_blocks')
        ->and($event->toArray()['type'])->toBe('tool_approval_request');
});
