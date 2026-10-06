<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\InteractiveAgent;
use Tests\Fixtures\Agents\RememberingInteractiveAgent;

function interactiveToolCall(string $tool, array $input, string $id = 'toolu_1'): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function interactiveText(string $text): array
{
    return [
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

beforeEach(function () {
    Config::set('ai.conversations.generate_title', false);
});

test('a call missing the values a tool needs pauses carrying its schema', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $pending = $paused->pendingApprovals->sole();

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($pending->isInteractive())->toBeTrue()
        ->and($pending->schema['required'])->toBe(['answer'])
        ->and($pending->schema['properties']['answer']['enum'])->toBe(['Basic', 'Pro'])
        ->and($pending->arguments)->toBe(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]);
});

test('a call already carrying the values runs without a round-trip', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('SilentGeolocationTool', ['latitude' => '48.85']))
            ->push(interactiveText('Got it.')),
    ]);

    $response = (new InteractiveAgent)->prompt('Where am I?', provider: 'anthropic');

    expect($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults->sole()->result)->toBe('at: 48.85');
});

test('a null value counts as missing', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('SilentGeolocationTool', ['latitude' => null])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->schema['required'])->toBe(['latitude']);
});

test('a submission changes only the keys it names, leaving the rest of the call intact', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('InteractiveChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(interactiveText('Good choice.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingInteractiveAgent)
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

test('the data a tool returns reaches the client but never the model', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('ReceiptTool', ['total' => '$41.00']))
            ->push(interactiveText('Thanks.')),
    ]);

    $response = (new InteractiveAgent)->prompt('Show my receipt.', provider: 'anthropic');

    $result = $response->toolResults->sole();

    expect($result->result)->toBe('Total: $41.00')
        ->and($result->data)->toBe(['total' => '$41.00']);

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
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Approval decisions are missing values the tool asked for.');

test('a pause waiting on values refuses a wildcard approval', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::approveAll(), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Approval decisions are missing values the tool asked for.');

test('a pause waiting on values refuses a submission that skips one', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => Decision::submit(['note' => 'later'])]), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Approval decisions are missing values the tool asked for.');

test('a pause waiting on values may still be rejected', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('InteractiveChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(interactiveText('No problem.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::rejectAll('Cancelled.'), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('Cancelled.');
});

test('an approvable tool may describe its approval with data without waiting on values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('DescribedApprovalTool', []))
            ->push(interactiveText('Done.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Delete my account.', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->reason)->toBe('Deletes the account.')
        ->and($paused->pendingApprovals->sole()->data)->toBe(['scope' => 'account:delete'])
        ->and($paused->pendingApprovals->sole()->isInteractive())->toBeFalse();

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('deleted');
});

test('a tool that needs both values and approval asks for approval once it has them', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('GuardedGeolocationTool', ['latitude' => '48.85']))
            ->push(interactiveText('Got it.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->isInteractive())->toBeFalse()
        ->and($paused->pendingApprovals->sole()->reason)->toBe('Uses your location.');

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('at: 48.85');
});

test('a tool that needs both values and approval asks for the values first', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('GuardedGeolocationTool', [])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->isInteractive())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->reason)->toBeNull();
});

test('a streamed tool result event carries the data the tool returned', function () {
    InteractiveAgent::fake([
        new ToolCall('call_1', 'ReceiptTool', ['total' => '$41.00']),
        'Thanks.',
    ]);

    $response = (new InteractiveAgent)->stream('Show my receipt.');
    $response->each(fn (): true => true);

    $event = collect($response->events)->first(fn ($event): bool => $event instanceof ToolResultEvent);

    expect($event->toArray()['data'])->toBe(['total' => '$41.00'])
        ->and($event->toArray()['result'])->toBe('Total: $41.00');
});
