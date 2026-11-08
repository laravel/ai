<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\InvalidToolInputException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\AskingAgent;
use Tests\Fixtures\Agents\RememberingAskingAgent;
use Tests\Fixtures\Agents\TripPlanner;
use Tests\Fixtures\Tools\ChoiceTool;

function requiresInputToolCall(string $tool, array $input, string $id = 'toolu_1'): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function requiresInputText(string $text): array
{
    return [
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function askUserCall(): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_ask', 'name' => 'AskUser', 'input' => [
            'question' => 'What do you want to do in Lisbon?',
            'options' => ['Food tours', 'Museums', 'Beaches', 'Nightlife'],
            'multiple' => true,
        ]]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function askUserReply(): array
{
    return [
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => 'Here is a 3-day plan...']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

beforeEach(function () {
    Config::set('ai.conversations.generate_title', false);
});

test('a call missing the values a tool needs pauses carrying its schema', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $pending = $paused->pendingApprovals->sole();

    expect($pending->requiresInput())->toBeTrue()
        ->and($pending->inputSchema['properties']['answer']['enum'])->toBe(['Basic', 'Pro'])
        ->and($pending->arguments)->toBe(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]);
});

test('a call already carrying the values runs without a round-trip', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('SilentGeolocationTool', ['latitude' => '48.85']))
            ->push(requiresInputText('Got it.')),
    ]);

    $response = (new AskingAgent)->prompt('Where am I?', provider: 'anthropic');

    expect($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults->sole()->result)->toBe('at: 48.85');
});

test('a value the model sets for a key only the caller may supply never reaches the tool', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro'], 'answer' => 'Pro']))
            ->push(requiresInputText('Good choice.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    expect($paused->toolResults)->toBeEmpty()
        ->and($paused->pendingApprovals->sole()->requiresInput())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->arguments)->toBe(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']])
        ->and(app(ConversationStore::class)->pendingApprovalsFor($paused->conversationId)[0]->arguments)->toBe(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]);

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Basic'])]), provider: 'anthropic');

    expect($resumed->toolResults->firstWhere('id', 'toolu_1')->result)->toBe('chose: Basic');
});

test('an invalid submission renders the pause again for JSON clients', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    try {
        (new RememberingAskingAgent)
            ->continue($paused->conversationId, (object) ['id' => 1])
            ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Gold'])]), provider: 'anthropic');

        $this->fail('The submission should have been refused.');
    } catch (InvalidToolInputException $exception) {
        $json = $exception->render(Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));

        expect($json->getStatusCode())->toBe(422)
            ->and($json->getData(true)['errors'])->toBe(['toolu_1.answer' => ['The selected answer is invalid.']])
            ->and($json->getData(true)['approvals'][0]['input_schema']['properties']['answer']['enum'])->toBe(['Basic', 'Pro'])
            ->and($exception->render(Request::create('/', 'POST')))->toBeNull();
    }
});

test('a null value counts as missing', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('SilentGeolocationTool', ['latitude' => null])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->requiresInput())->toBeTrue();
});

test('a submission changes only the keys it names, leaving the rest of the call intact', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(requiresInputText('Good choice.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from([
            'toolu_1' => Decision::submit(['answer' => 'Pro']),
        ]), provider: 'anthropic');

    $result = $resumed->toolResults->firstWhere('id', 'toolu_1');

    expect($result->result)->toBe('chose: Pro')
        ->and($result->arguments)->toBe([
            'question' => 'Which plan?',
            'options' => ['Basic', 'Pro'],
            'answer' => 'Pro',
        ]);
});

test('a submission ignores keys the tool did not ask for', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(requiresInputText('Good choice.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from([
            'toolu_1' => Decision::submit(['answer' => 'Pro', 'question' => 'Injected']),
        ]), provider: 'anthropic');

    expect($resumed->toolResults->firstWhere('id', 'toolu_1')->arguments['question'])->toBe('Which plan?');
});

test('the data a tool returns reaches the client but never the model', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ReceiptTool', ['total' => '$41.00']))
            ->push(requiresInputText('Thanks.')),
    ]);

    $response = (new AskingAgent)->prompt('Show my receipt.', provider: 'anthropic');

    expect($response->toolResults->sole()->data)->toBe(['total' => '$41.00']);

    $sent = collect(Http::recorded())->last()[0]->data();

    $toolResult = collect($sent['messages'])
        ->pluck('content')
        ->flatten(1)
        ->firstWhere('type', 'tool_result');

    expect(json_encode($toolResult))->toContain('Total: $41.00')
        ->and(json_encode($toolResult))->not->toContain('"total"');
});

test('a pause waiting on values refuses a bare approval', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
})->throws(ValidationException::class, 'The answer field is required.');

test('a pause waiting on values refuses a wildcard approval', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::approveAll(), provider: 'anthropic');
})->throws(ValidationException::class, 'The answer field is required.');

test('a pause waiting on values refuses a submission that skips one', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['note' => 'later'])]), provider: 'anthropic');
})->throws(ValidationException::class, 'The answer field is required.');

test('a submission outside the schema is refused before the tool runs and the pause stays open', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(requiresInputText('Good choice.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    expect(fn () => (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Gold'])]), provider: 'anthropic'))
        ->toThrow(ValidationException::class);

    Http::assertSentCount(1);

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Pro'])]), provider: 'anthropic');

    expect($resumed->toolResults->firstWhere('id', 'toolu_1')->result)->toBe('chose: Pro');
});

test('a submitted null counts as missing', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => null])]), provider: 'anthropic');
})->throws(ValidationException::class, 'The answer field is required.');

test('an edit is held to the same input schema as a submission', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::edit([
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'], 'answer' => 'Gold',
        ])]), provider: 'anthropic');
})->throws(ValidationException::class, 'The selected answer is invalid.');

test('a streamed resume refuses an invalid submission before the stream starts', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('ChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->stream(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Gold'])]), provider: 'anthropic');
})->throws(ValidationException::class, 'The selected answer is invalid.');

test('a pause waiting on values may still be rejected', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(requiresInputText('No problem.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::rejectAll('Cancelled.'), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('Cancelled.');
});

test('an approvable tool may describe its approval with data without waiting on values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('DescribedApprovalTool', []))
            ->push(requiresInputText('Done.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Delete my account.', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->reason)->toBe('Deletes the account.')
        ->and($paused->pendingApprovals->sole()->data)->toBe(['scope' => 'account:delete'])
        ->and($paused->pendingApprovals->sole()->requiresInput())->toBeFalse();

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('deleted');
});

test('a pause waiting only on approval refuses submitted values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('DescribedApprovalTool', [])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Delete my account.', provider: 'anthropic');

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['confirm' => true])]), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Values may only be submitted to a pause waiting on input.');

test('a tool that needs both values and approval asks for approval once it has them', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('GuardedGeolocationTool', ['latitude' => '48.85']))
            ->push(requiresInputText('Got it.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->requiresInput())->toBeFalse()
        ->and($paused->pendingApprovals->sole()->reason)->toBe('Uses your location.');

    $resumed = (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('at: 48.85');
});

test('a tool that needs both values and approval asks for the values first', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(requiresInputToolCall('GuardedGeolocationTool', [])),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->requiresInput())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->reason)->toBe('Uses your location.');
});

test('a streamed tool result event carries the data the tool returned', function () {
    AskingAgent::fake([
        new ToolCall('call_1', 'ReceiptTool', ['total' => '$41.00']),
        'Thanks.',
    ]);

    $response = (new AskingAgent)->stream('Show my receipt.');
    $response->each(fn (): true => true);

    $event = collect($response->events)->first(fn ($event): bool => $event instanceof ToolResultEvent);

    expect($event->toArray()['data'])->toBe(['total' => '$41.00'])
        ->and($event->toArray()['result'])->toBe('Total: $41.00');
});

test('the model asks, the user picks several options and types one of their own', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(askUserCall())->push(askUserReply())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    $pending = $paused->pendingApprovals->sole();

    expect($pending->requiresInput())->toBeTrue();

    $resumed = (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from([$pending->id => Decision::submit(['answers' => ['Food tours', 'Fado night']])]),
        provider: 'anthropic',
    );

    $result = $resumed->toolResults->firstWhere('id', 'toolu_ask');

    expect($result->result)->toBe('The user answered: Food tours, Fado night. Typed by the user: Fado night')
        ->and($result->data['typed'])->toBe(['Fado night']);
});

test('an answer without the answers key is refused before the tool runs', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(askUserCall())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => true]),
        provider: 'anthropic',
    );
})->throws(ValidationException::class, 'The answers field is required.');

test('the user may rewrite the question and options along with the answer', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(askUserCall())->push(askUserReply())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    $resumed = (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => Decision::edit([
            'question' => 'What do you want to do in Porto?',
            'options' => ['Wine cellars', 'River cruise'],
            'multiple' => false,
            'answers' => ['Wine cellars'],
        ])]),
        provider: 'anthropic',
    );

    expect($resumed->toolResults->firstWhere('id', 'toolu_ask')->result)->toBe('The user answered: Wine cellars');
});

test('an edit is held to the input schema of its edited arguments', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(askUserCall())]);

    $paused = (new TripPlanner)->forUser((object) ['id' => 1])->prompt('Plan 3 days in Lisbon.', provider: 'anthropic');

    (new TripPlanner)->continue($paused->conversationId, (object) ['id' => 1])->prompt(
        Decisions::from(['toolu_ask' => Decision::edit([
            'question' => 'What do you want to do in Porto?',
            'options' => ['Wine cellars', 'River cruise'],
            'multiple' => false,
            'answers' => ['Wine cellars', 'River cruise'],
        ])]),
        provider: 'anthropic',
    );
})->throws(ValidationException::class, 'The answers field must not have more than 1 items.');

test('a resume asks the tool for its input only while validating the submission', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(requiresInputToolCall('ChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(requiresInputText('Good choice.')),
    ]);

    $paused = (new RememberingAskingAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    ChoiceTool::$inputRequests = 0;

    (new RememberingAskingAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['answer' => 'Pro'])]), provider: 'anthropic');

    expect(ChoiceTool::$inputRequests)->toBe(3);
});
