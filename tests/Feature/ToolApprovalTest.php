<?php

use Illuminate\Http\Request;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Approvals\ToolApproval;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

test('tool approval from treats a wildcard key as the default for undecided calls', function () {
    $approval = ToolApproval::from(['*' => true, 'call-1' => false]);

    expect($approval->default->action)->toBe('approve')
        ->and($approval->decisions)->toHaveKeys(['call-1'])
        ->and($approval->decisions['call-1']->action)->toBe('reject');
});

test('tool approval from accepts a wildcard rejection with a result', function () {
    $approval = ToolApproval::from(['*' => Approval::reject('Not now')]);

    expect($approval->decisions)->toBe([])
        ->and($approval->default->action)->toBe('reject')
        ->and($approval->default->result)->toBe('Not now');
});

test('tool approval from rejects a wildcard edit decision', function () {
    ToolApproval::from(['*' => Approval::edit(['path' => '/tmp/file'])]);
})->throws(InvalidArgumentException::class, 'The wildcard decision may not use the edit action.');

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
