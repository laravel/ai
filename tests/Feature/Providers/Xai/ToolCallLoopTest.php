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

    $firstBody = json_decode($recorded[0][0]->body(), true);
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect(data_get($firstBody, 'tool_choice'))->toBe('auto')
        ->and($firstBody)->toHaveKey('tools')
        ->and(data_get($followUpBody, 'tool_choice'))->toBe('auto')
        ->and($followUpBody)->toHaveKey('tools')
        ->and($followUpBody['tools'])->not->toBeEmpty();
});
