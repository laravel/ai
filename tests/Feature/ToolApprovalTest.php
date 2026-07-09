<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Approvals\ToolApproval;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

test('tool approval can be built from the canonical request payload', function () {
    $approval = ToolApproval::fromRequest(Request::create('/chat', 'POST', [
        'decisions' => [
            ['id' => 'call-1', 'action' => 'approve'],
            ['id' => 'call-2', 'action' => 'reject'],
            ['id' => 'call-3', 'action' => 'reason', 'result' => 'Already deleted'],
            ['id' => 'call-4', 'action' => 'edit', 'arguments' => ['path' => '/tmp/file']],
        ],
    ]));

    expect($approval)->toBeInstanceOf(ToolApproval::class)
        ->and($approval->decisions['call-1']->action)->toBe('approve')
        ->and($approval->decisions['call-2']->action)->toBe('reject')
        ->and($approval->decisions['call-2']->result)->toBeNull()
        ->and($approval->decisions['call-3']->result)->toBe('Already deleted')
        ->and($approval->decisions['call-4']->arguments)->toBe(['path' => '/tmp/file']);
});

test('tool approval requires a result for the reason action', function () {
    ToolApproval::fromRequest(Request::create('/chat', 'POST', [
        'decisions' => [
            ['id' => 'call-1', 'action' => 'reason'],
        ],
    ]));
})->throws(ValidationException::class);

test('tool approval rejects a result on a bare reject', function () {
    ToolApproval::fromRequest(Request::create('/chat', 'POST', [
        'decisions' => [
            ['id' => 'call-1', 'action' => 'reject', 'result' => 'Already deleted'],
        ],
    ]));
})->throws(ValidationException::class);

test('tool approval request parsing returns null when no decisions are present', function () {
    expect(ToolApproval::fromRequest(Request::create('/chat', 'POST', [
        'message' => 'Hello',
    ])))->toBeNull();
});

test('tool approval request parsing validates malformed decisions', function () {
    ToolApproval::fromRequest(Request::create('/chat', 'POST', [
        'decisions' => [
            ['id' => 'call-1', 'action' => 'edit'],
        ],
    ]));
})->throws(ValidationException::class);

test('agent responses render awaiting approval and complete payloads', function () {
    $pending = new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file');

    $paused = AgentResponse::fakeAwaitingApproval([$pending])->withinConversation('conversation-1');
    $complete = (new AgentResponse('invocation-1', 'Done', new Usage, new Meta))->withinConversation('conversation-1');

    expect($paused->toResponse(Request::create('/'))->getData(true))->toBe([
        'status' => 'awaiting_approval',
        'conversation_id' => 'conversation-1',
        'approvals' => [$pending->toArray()],
    ])->and($complete->toResponse(Request::create('/'))->getData(true))->toBe([
        'status' => 'complete',
        'conversation_id' => 'conversation-1',
        'reply' => 'Done',
    ]);
});

test('structured agent responses render their payload rather than the responsable envelope', function () {
    $response = new StructuredAgentResponse('invocation-1', ['number' => 72019], '72019', new Usage, new Meta);

    expect($response->toResponse(Request::create('/'))->getData(true))->toBe(['number' => 72019]);
});

test('tool approval from normalizes boolean decisions', function () {
    $approval = ToolApproval::from([
        'call-1' => true,
        'call-2' => false,
        'call-3' => Approval::edit(['path' => '/tmp/file']),
    ]);

    expect($approval->decisions['call-1']->action)->toBe('approve')
        ->and($approval->decisions['call-2']->action)->toBe('reject')
        ->and($approval->decisions['call-3']->arguments)->toBe(['path' => '/tmp/file']);
});
