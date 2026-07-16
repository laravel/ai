<?php

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

test('an approval mismatch renders as a 409 carrying the pending approvals', function () {
    $exception = new ApprovalMismatchException('Approval decisions do not match the pending tool calls.', collect([
        new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a tracked project file'),
    ]));

    $response = $exception->render(new Request);

    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode($response->getContent(), true))->toBe([
            'message' => 'Approval decisions do not match the pending tool calls.',
            'approvals' => [[
                'id' => 'call-1',
                'tool' => 'DeleteFile',
                'arguments' => ['path' => 'config/app.php'],
                'reason' => 'Deletes a tracked project file',
            ]],
        ]);
});

test('decision maps normalize boolean decisions', function () {
    $approval = Decision::normalize([
        'call-1' => true,
        'call-2' => false,
        'call-3' => Decision::edit(['path' => '/tmp/file']),
    ]);

    expect($approval['call-1']->isApproved())->toBeTrue()
        ->and($approval['call-2']->isRejected())->toBeTrue()
        ->and($approval['call-3']->isEdited())->toBeTrue()
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

test('the blanket helpers approve or reject every pending call', function () {
    $approveAll = Decision::approveAll();
    $rejectAll = Decision::rejectAll();

    expect($approveAll['*']->isApproved())->toBeTrue()
        ->and($rejectAll['*']->isRejected())->toBeTrue()
        ->and($rejectAll['*']->result)->toBeNull();
});

test('a paused response exposes everything a controller needs to build its own approval envelope', function () {
    $pending = new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file');

    $paused = AgentResponse::fakeAwaitingApproval([$pending])->withinConversation('conversation-1');

    expect($paused->awaitingApproval())->toBeTrue()
        ->and($paused->conversationId)->toBe('conversation-1')
        ->and($paused->pendingApprovals->toArray())->toBe([$pending->toArray()]);
});

test('a completed response exposes its reply and conversation without an envelope', function () {
    $complete = (new AgentResponse('invocation-1', 'Done', new Usage, new Meta))->withinConversation('conversation-1');

    expect($complete->awaitingApproval())->toBeFalse()
        ->and($complete->conversationId)->toBe('conversation-1')
        ->and($complete->text)->toBe('Done');
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
        return ($prompt->resume['call-1'] ?? null)?->isApproved() === true;
    });
});

test('approval resume prompts can be broadcast', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)
        ->broadcast(['call-1' => true], new Channel('approvals'))
        ->each(fn () => true);

    ConversationalAgent::assertPrompted(function ($prompt) {
        return ($prompt->resume['call-1'] ?? null)?->isApproved() === true;
    });
});

test('approval resume prompts can be broadcast on the queue', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)->broadcastOnQueue(['call-1' => true], new Channel('approvals'));

    ConversationalAgent::assertQueued(function ($prompt) {
        return ($prompt->resume['call-1'] ?? null)?->isApproved() === true;
    });
});

test('the blanket approve helper widens to every pending call', function () {
    ConversationalAgent::fake();

    (new ConversationalAgent)->queue(Decision::approveAll());

    ConversationalAgent::assertQueued(function ($prompt) {
        return ($prompt->resume['*'] ?? null)?->isApproved() === true;
    });
});

test('a run that only pauses does not dispatch the tool approval resolved event', function () {
    Config::set('ai.conversations.generate_title', false);

    Event::fake([ToolApprovalResolved::class]);

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

    (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'anthropic');

    Event::assertNotDispatched(ToolApprovalResolved::class);
});

test('an approved resume dispatches the tool approval resolved event with the resolved tool results', function () {
    Config::set('ai.conversations.generate_title', false);

    Event::fake([ToolApprovalResolved::class]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
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
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(['toolu_1' => true], provider: 'anthropic');

    Event::assertDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event) {
        return $event->toolResults->count() === 1
            && $event->toolResults[0]->id === 'toolu_1'
            && $event->toolResults[0]->denied === false
            && $event->conversationId !== null;
    });
});

test('a rejected resume dispatches the tool approval resolved event with a denied result', function () {
    Config::set('ai.conversations.generate_title', false);

    Event::fake([ToolApprovalResolved::class]);

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

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->prompt(['toolu_1' => false], provider: 'anthropic');

    Event::assertDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event) {
        return $event->toolResults->count() === 1
            && $event->toolResults[0]->id === 'toolu_1'
            && $event->toolResults[0]->denied === true;
    });
});

test('an approved streamed resume dispatches the tool approval resolved event', function () {
    Config::set('ai.conversations.generate_title', false);

    Event::fake([ToolApprovalResolved::class]);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
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
            Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];

    $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    $response = (new RememberingApprovableAgent)
        ->continue($paused->conversationId, $user)
        ->stream(['toolu_1' => true], provider: 'anthropic');

    $response->each(fn () => true);

    Event::assertDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event) {
        return $event->toolResults->count() === 1
            && $event->toolResults[0]->id === 'toolu_1'
            && $event->toolResults[0]->denied === false;
    });
});
