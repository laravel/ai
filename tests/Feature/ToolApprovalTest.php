<?php

use Illuminate\Contracts\Support\Responsable;
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

test('decision maps normalize boolean decisions', function () {
    $approval = Decision::normalize([
        'call-1' => true,
        'call-2' => false,
        'call-3' => Decision::edit(['path' => '/tmp/file']),
    ]);

    expect($approval['call-1']->action)->toBe('approve')
        ->and($approval['call-2']->action)->toBe('reject')
        ->and($approval['call-3']->arguments)->toBe(['path' => '/tmp/file']);
});

test('decision maps reject a wildcard edit decision', function () {
    Decision::normalize(['*' => Decision::edit(['path' => '/tmp/file'])]);
})->throws(InvalidArgumentException::class, 'The wildcard decision may not use the edit action.');

test('decision maps reject values that are not decisions or booleans', function () {
    Decision::normalize(['call-1' => 'approve']);
})->throws(InvalidArgumentException::class, 'Tool approval decisions must be Decision instances or booleans.');

test('decision maps reject a nested decision map so it cannot fall through to approval', function () {
    Decision::normalize(['call-1' => ['call-1' => false]]);
})->throws(InvalidArgumentException::class, 'Tool approval decisions must be Decision instances or booleans.');

test('an empty decision map through prompt is rejected instead of silently resuming nothing', function () {
    (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt([]);
})->throws(InvalidArgumentException::class, 'Tool approval decisions may not be empty.');

test('a blank rejection reason is treated as a bare rejection that stops the loop', function () {
    expect(Decision::reject('')->result)->toBeNull()
        ->and(Decision::reject('   ')->result)->toBeNull()
        ->and(Decision::reject('Already handled')->result)->toBe('Already handled');
});

test('a bare edit decision through prompt asks for a keyed collection instead of a misleading wildcard error', function () {
    (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt(Decision::edit(['path' => '/tmp/x']));
})->throws(InvalidArgumentException::class, 'A bare edit decision has no tool call to target');

test('the approval response adapter renders awaiting approval and complete payloads', function () {
    $pending = new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file');

    $paused = AgentResponse::fakeAwaitingApproval([$pending])->withinConversation('conversation-1');
    $complete = (new AgentResponse('invocation-1', 'Done', new Usage, new Meta))->withinConversation('conversation-1');

    expect($paused->toApprovalResponse()->toResponse(Request::create('/'))->getData(true))->toBe([
        'status' => 'awaiting_approval',
        'conversation_id' => 'conversation-1',
        'approvals' => [$pending->toArray()],
    ])->and($complete->toApprovalResponse()->toResponse(Request::create('/'))->getData(true))->toBe([
        'status' => 'complete',
        'conversation_id' => 'conversation-1',
        'reply' => 'Done',
    ]);
});

test('agent responses are not globally responsable so a normal reply still renders as text', function () {
    $response = (new AgentResponse('invocation-1', 'Done', new Usage, new Meta));

    expect($response)->not->toBeInstanceOf(Responsable::class)
        ->and((string) $response)->toBe('Done');
});

test('structured agent responses render their payload rather than the approval envelope', function () {
    $response = new StructuredAgentResponse('invocation-1', ['number' => 72019], '72019', new Usage, new Meta);

    expect($response->toJson())->toBe(json_encode(['number' => 72019]))
        ->and($response->toArray())->toBe(['number' => 72019]);
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

    (new ConversationalAgent)->queue(['call-1' => true]);

    ConversationalAgent::assertQueued(function ($prompt) {
        return ($prompt->resume['call-1'] ?? null)?->action === 'approve';
    });
});

test('a bare decision widens to every pending call', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)->queue(Decision::approve());

    ConversationalAgent::assertQueued(function ($prompt) {
        return ($prompt->resume['*'] ?? null)?->action === 'approve';
    });
});
