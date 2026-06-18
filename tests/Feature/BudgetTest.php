<?php

use Laravel\Ai\Exceptions\BudgetExceededException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Agents\BudgetedAgent;

test('an agent with a MaxCost budget throws once its budget is exhausted', function () {
    config(['ai.pricing.models' => [
        'openai' => ['budget-test-model' => ['input' => 0.006]],
    ]]);

    // Each call costs $0.006 (1M prompt tokens * $0.006/1M); the agent's budget is $0.005.
    BudgetedAgent::fake(fn () => new TextResponse(
        'ok',
        new Usage(promptTokens: 1_000_000),
        new Meta('openai', 'budget-test-model'),
    ));

    // First call is under budget and succeeds...
    expect((new BudgetedAgent)->prompt('first')->text)->toBe('ok');

    // Accumulated $0.006 now exceeds the $0.005 budget, so the next call is blocked.
    expect(fn () => (new BudgetedAgent)->prompt('second'))
        ->toThrow(BudgetExceededException::class);
});

test('budget does not trip for models without known pricing', function () {
    BudgetedAgent::fake(fn () => new TextResponse(
        'ok',
        new Usage(promptTokens: 1_000_000),
        new Meta('openai', 'unpriced-model'),
    ));

    expect((new BudgetedAgent)->prompt('first')->text)->toBe('ok')
        ->and((new BudgetedAgent)->prompt('second')->text)->toBe('ok');
});
