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

test('an interactive tool pauses carrying the payload its ask returned', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->meta)->toBe([
            'question' => 'Which plan?',
            'options' => ['Basic', 'Pro'],
        ]);
});

test('an ask that returns null runs the tool without a round-trip', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('SilentGeolocationTool', ['latitude' => '48.85']))
            ->push(interactiveText('Got it.')),
    ]);

    $response = (new InteractiveAgent)->prompt('Where am I?', provider: 'anthropic');

    expect($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults->sole()->result)->toBe('at: 48.85');
});

test('an ask that returns an empty payload still pauses', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('SilentGeolocationTool', ['latitude' => null])),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->meta)->toBe([]);
});

test('a submission changes only the keys it names, leaving the rest of the call intact', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('InteractiveChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(interactiveText('Good choice.')),
    ]);

    $agent = new RememberingInteractiveAgent;

    $paused = $agent->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

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

test('the client submits arguments, so the meta a tool returns never reaches the model', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('ReceiptTool', ['total' => '$41.00']))
            ->push(interactiveText('Thanks.')),
    ]);

    $response = (new InteractiveAgent)->prompt('Show my receipt.', provider: 'anthropic');

    $result = $response->toolResults->sole();

    expect($result->result)->toBe('Total: $41.00')
        ->and($result->meta)->toBe(['total' => '$41.00']);

    $sent = collect(Http::recorded())->last()[0]->data();

    $toolResult = collect($sent['messages'])
        ->pluck('content')
        ->flatten(1)
        ->firstWhere('type', 'tool_result');

    expect(json_encode($toolResult))->toContain('Total: $41.00')
        ->and(json_encode($toolResult))->not->toContain('"total"');
});

test('an interactive pause refuses a bare approval', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $agent = new RememberingInteractiveAgent;

    $paused = $agent->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Interactive tool calls must be answered with a submission.');

test('an interactive pause refuses a wildcard approval', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(interactiveToolCall('InteractiveChoiceTool', [
            'question' => 'Which plan?', 'options' => ['Basic', 'Pro'],
        ])),
    ]);

    $agent = new RememberingInteractiveAgent;

    $paused = $agent->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::approveAll(), provider: 'anthropic');
})->throws(ApprovalMismatchException::class, 'Interactive tool calls must be answered with a submission.');

test('an interactive pause may still be rejected', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('InteractiveChoiceTool', ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']]))
            ->push(interactiveText('No problem.')),
    ]);

    $agent = new RememberingInteractiveAgent;

    $paused = $agent->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decision::rejectAll('Cancelled.'), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('Cancelled.');
});

test('the wildcard decision may not submit', function () {
    Decision::normalize(['*' => Decision::submit(['answer' => 'Pro'])]);
})->throws(InvalidArgumentException::class, 'The wildcard decision may only approve or reject.');

test('an approvable tool may describe its approval with a meta without requiring a submission', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('DescribedApprovalTool', []))
            ->push(interactiveText('Done.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Delete my account.', provider: 'anthropic');

    expect($paused->pendingApprovals->sole()->reason)->toBe('Deletes the account.')
        ->and($paused->pendingApprovals->sole()->meta)->toBe(['scope' => 'account:delete']);

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('deleted');
});

test('an interactive tool whose ask returns null still requests approval when it is approvable', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(interactiveToolCall('GuardedGeolocationTool', ['latitude' => '48.85']))
            ->push(interactiveText('Got it.')),
    ]);

    $paused = (new RememberingInteractiveAgent)->forUser((object) ['id' => 1])->prompt('Where am I?', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->sole()->meta)->toBeNull();

    $resumed = (new RememberingInteractiveAgent)
        ->continue($paused->conversationId, (object) ['id' => 1])
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->toolResults->sole()->result)->toBe('at: 48.85');
});

test('a streamed tool result event carries the meta the tool returned', function () {
    InteractiveAgent::fake([
        new ToolCall('call_1', 'ReceiptTool', ['total' => '$41.00']),
        'Thanks.',
    ]);

    $response = (new InteractiveAgent)->stream('Show my receipt.');
    $response->each(fn (): true => true);

    $event = collect($response->events)->first(fn ($event): bool => $event instanceof ToolResultEvent);

    expect($event->toArray()['meta'])->toBe(['total' => '$41.00'])
        ->and($event->toArray()['result'])->toBe('Total: $41.00');
});
