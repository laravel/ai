<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'xai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id');

    $hasToolOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasToolOutput = true;
        }
    }

    expect($hasToolOutput)->toBeTrue('Follow-up request should include function_call_output');
});

test('max steps limits tool call depth', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'xai',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool call follow up requests preserve the originally requested model alias', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponseWithSnapshot('grok-4-1-fast-reasoning-2026-04-28'),
            $this->fakeToolCallResponseWithSnapshot('grok-4-1-fast-reasoning-2026-04-28'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true, maxStepsOverride: 5))->prompt(
        'Generate a random number',
        provider: 'xai',
        model: 'grok-4-1-fast-reasoning',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    $firstRequestBody = json_decode($recorded[0][0]->body(), true);
    $firstFollowUpBody = json_decode($recorded[1][0]->body(), true);
    $secondFollowUpBody = json_decode($recorded[2][0]->body(), true);

    expect($firstRequestBody['model'])->toBe('grok-4-1-fast-reasoning')
        ->and($firstFollowUpBody['model'])->toBe('grok-4-1-fast-reasoning')
        ->and($secondFollowUpBody['model'])->toBe('grok-4-1-fast-reasoning');
});

test('follow up request preserves tools', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'xai',
    );

    $recorded = Http::recorded();

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('tools')
        ->and($followUpBody['tools'])->not->toBeEmpty();
});
