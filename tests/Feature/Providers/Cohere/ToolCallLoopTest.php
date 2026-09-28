<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
            $this->fakeTextResponse('The random number generated is 72019.'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

    expect(Http::recorded())->toHaveCount(2)
        ->and($response->text)->toBe('The random number generated is 72019.')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('FixedNumberGenerator')
        ->and($response->toolResults->first()->result)->toBe('72019')
        ->and($response->steps->first()->finishReason)->toBe(FinishReason::ToolCalls);
});

test('tool call arguments are decoded', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('RandomNumberGenerator', 'call_1', '{"min":1,"max":10}'),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent)->prompt('Generate a random number', provider: 'cohere');

    expect($response->toolCalls->first()->arguments)->toBe(['min' => 1, 'max' => 10]);
});

test('empty tool call arguments decode to an empty array', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1', ''),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

    expect($response->toolCalls->first()->arguments)->toBe([]);
});

test('multi step tool loop accumulates usage', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt('Generate a number', provider: 'cohere');

    expect($response->steps)->toHaveCount(2)
        ->and($response->usage->inputTokens)->toBe(1439 + 557)
        ->and($response->usage->outputTokens)->toBe(47 + 8);
});

test('unregistered tool call throws', function (): void {
    Http::fake(['*' => $this->fakeToolCallResponse('UnknownTool', 'call_1')]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'cohere');
})->throws(NoSuchToolException::class);
