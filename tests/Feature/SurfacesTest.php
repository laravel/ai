<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Presentable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\RememberingSurfaceAgent;
use Tests\Fixtures\Surfaces\ChoiceCard;
use Tests\Fixtures\Tools\InteractiveChoiceTool;
use Tests\Fixtures\Tools\PresentableReceiptTool;
use Tests\Fixtures\Tools\PresentedChoiceTool;

test('a presentable tool declares the surface a call renders as', function () {
    $surface = (new PresentedChoiceTool)->present(
        new Request(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']], 'call-1')
    );

    expect($surface)->toBeInstanceOf(ChoiceCard::class)
        ->and($surface::name())->toBe('choice-card')
        ->and($surface::actions())->toBe(['select'])
        ->and($surface->toArray())->toBe([
            'question' => 'Which plan?',
            'options' => ['Basic', 'Pro'],
        ]);
});

test('presenting says nothing about whether the run pauses', function () {
    expect(new PresentedChoiceTool)->toBeInstanceOf(Presentable::class)
        ->and(new InteractiveChoiceTool)->toBeInstanceOf(Presentable::class);

    // The same surface, from a tool that pauses and one that does not.
    $arguments = new Request(['question' => 'Which plan?', 'options' => ['Basic', 'Pro']], 'call-1');

    expect((new PresentedChoiceTool)->present($arguments)::name())
        ->toBe((new InteractiveChoiceTool)->present($arguments)::name());
});

test('a surface that sends nothing back declares no actions', function () {
    $surface = (new PresentableReceiptTool)->present(new Request(['total' => '$41.00'], 'call-2'));

    expect($surface::actions())->toBe([])
        ->and($surface->toArray())->toBe(['total' => '$41.00']);
});

test('a merge changes only the keys it names, leaving the rest of the call intact', function () {
    Config::set('ai.conversations.generate_title', false);

    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'InteractiveChoiceTool',
                    'input' => ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']],
                ]],
                'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            Http::response([
                'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Good choice.']],
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $agent = new RememberingSurfaceAgent;

    $paused = $agent->forUser((object) ['id' => 1])->prompt('Ask me something.', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    $resumed = $agent->prompt(Decisions::from([
        'toolu_1' => Decision::merge(['answer' => 'Pro']),
    ]), provider: 'anthropic');

    $result = $resumed->toolResults->firstWhere('id', 'toolu_1');

    expect($result->result)->toBe('chose: Pro')
        ->and($result->arguments)->toBe([
            'question' => 'Which plan?',
            'options' => ['Basic', 'Pro'],
            'answer' => 'Pro',
        ]);
});

test('the wildcard decision may not merge', function () {
    Decision::normalize(['*' => Decision::merge(['answer' => 'Pro'])]);
})->throws(InvalidArgumentException::class, 'The wildcard decision may not use the merge action.');

test('a paused presentable tool is faked and read exactly like any approval', function () {
    RememberingSurfaceAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: 'call_abc',
                tool: 'InteractiveChoiceTool',
                arguments: ['question' => 'Which plan?', 'options' => ['Basic', 'Pro']],
                reason: 'Needs a choice.',
            ),
        ]),
        'Good choice.',
    ]);

    $response = (new RememberingSurfaceAgent)->forUser((object) ['id' => 1])->prompt('Which plan?');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals->sole()->tool)->toBe('InteractiveChoiceTool');
});
