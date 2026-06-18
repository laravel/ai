<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('tool-loop follow-up uses the original request model, not the response model', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'xai',
        model: 'grok-4-1',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp['model'])->toBe('grok-4-1')
        ->and($followUp['model'])->not->toBe('grok-4-1-fast-reasoning')
        ->and($response->meta->model)->toBe('grok-4-1-fast-reasoning');
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

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('tools')
        ->and($followUpBody['tools'])->not->toBeEmpty();
});
