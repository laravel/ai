<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('tool-loop follow-up uses the original request model, not the response model', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'mistral',
        model: 'mistral-medium',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp['model'])->toBe('mistral-medium')
        ->and($followUp['model'])->not->toBe('mistral-medium-latest')
        ->and($response->meta->model)->toBe('mistral-medium-latest');
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
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
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('follow up request includes original messages', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'mistral',
    );

    $recorded = Http::recorded();

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $userMsg = collect($followUpBody['messages'])->firstWhere('role', 'user');

    expect($userMsg)->not->toBeNull();
});
