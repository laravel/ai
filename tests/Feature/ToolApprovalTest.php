<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

test('decision collections normalize boolean decisions', function () {
    $approval = Decision::collection([
        'call-1' => true,
        'call-2' => false,
        'call-3' => Decision::edit(['path' => '/tmp/file']),
    ]);

    expect($approval->decisions['call-1']->action)->toBe('approve')
        ->and($approval->decisions['call-2']->action)->toBe('reject')
        ->and($approval->decisions['call-3']->arguments)->toBe(['path' => '/tmp/file']);
});

test('decision collections reject a wildcard edit decision', function () {
    Decision::collection(['*' => Decision::edit(['path' => '/tmp/file'])]);
})->throws(InvalidArgumentException::class, 'The wildcard decision may not use the edit action.');

test('decision collections reject values that are not decisions or booleans', function () {
    Decision::collection(['call-1' => 'approve']);
})->throws(InvalidArgumentException::class, 'Tool approval decisions must be Decision instances or booleans.');

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

test('approval overrides take precedence over the tool default', function () {
    $tool = new ApprovableNumberGenerator;

    expect($tool->shouldRequestApproval(new ToolRequest([])))->not->toBeNull()
        ->and($tool->withoutApproval()->shouldRequestApproval(new ToolRequest([])))->toBeNull()
        ->and($tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([])))->not->toBeNull()
        ->and($tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([]))->reason)->toBe('Dangerous');
});

test('a paused prompt dispatches the tool approval requested event with its conversation', function () {
    Config::set('ai.conversations.generate_title', false);

    Event::fake([ToolApprovalRequested::class]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ApprovableNumberGenerator',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $paused = (new RememberingApprovableAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('Generate a number', provider: 'anthropic');

    Event::assertDispatched(ToolApprovalRequested::class, function (ToolApprovalRequested $event) use ($paused) {
        return $event->pendingApprovals->count() === 1
            && $event->pendingApprovals[0]->id === 'toolu_1'
            && $event->conversationId === $paused->conversationId
            && $event->conversationId !== null;
    });
});

test('a paused stream dispatches the tool approval requested event', function () {
    Event::fake([ToolApprovalRequested::class]);

    ConversationalAgent::fake([
        AgentResponse::fakeAwaitingApproval([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]),
    ]);

    $response = (new ConversationalAgent)->stream('Delete config/app.php');

    $response->each(fn () => true);

    Event::assertDispatched(ToolApprovalRequested::class, function (ToolApprovalRequested $event) {
        return $event->pendingApprovals->count() === 1
            && $event->pendingApprovals[0]->id === 'call-1';
    });
});

test('approval resume prompts can be queued', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)->queue(Decision::collection(['call-1' => true]));

    ConversationalAgent::assertQueued(function ($prompt) {
        return $prompt->resume?->decisions['call-1']->action === 'approve';
    });
});

test('a bare decision widens to every pending call', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)->queue(Decision::approve());

    ConversationalAgent::assertQueued(function ($prompt) {
        return $prompt->resume?->decisions['*']->action === 'approve';
    });
});
