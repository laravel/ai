<?php

use Laravel\Ai\Pricing\CostCalculator;
use Laravel\Ai\Pricing\PriceList;
use Laravel\Ai\Responses\Data\Usage;

function calculatorFor(array $overrides = []): CostCalculator
{
    return new CostCalculator(new PriceList($overrides));
}

test('calculates input and output cost from per-million rates', function () {
    $calc = calculatorFor(['openai' => ['m' => ['input' => 2.00, 'output' => 8.00]]]);

    $cost = $calc->calculate(new Usage(promptTokens: 1_000_000, completionTokens: 500_000), 'openai', 'm');

    expect($cost->input)->toBe(2.0)
        ->and($cost->output)->toBe(4.0)
        ->and($cost->total())->toBe(6.0)
        ->and($cost->isKnown())->toBeTrue();
});

test('cache and reasoning tokens cost nothing unless rates are configured', function () {
    $calc = calculatorFor(['openai' => ['m' => ['input' => 2.00, 'output' => 8.00]]]);

    $cost = $calc->calculate(new Usage(
        promptTokens: 0,
        completionTokens: 0,
        cacheWriteInputTokens: 1_000_000,
        cacheReadInputTokens: 1_000_000,
        reasoningTokens: 1_000_000,
    ), 'openai', 'm');

    expect($cost->total())->toBe(0.0);
});

test('cache and reasoning rates are applied when configured', function () {
    $calc = calculatorFor(['anthropic' => ['m' => [
        'input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75, 'reasoning' => 15.00,
    ]]]);

    $cost = $calc->calculate(new Usage(
        promptTokens: 1_000_000,
        completionTokens: 1_000_000,
        cacheWriteInputTokens: 1_000_000,
        cacheReadInputTokens: 1_000_000,
        reasoningTokens: 1_000_000,
    ), 'anthropic', 'm');

    expect($cost->cacheRead)->toBe(0.30)
        ->and($cost->cacheWrite)->toBe(3.75)
        ->and($cost->reasoning)->toBe(15.00)
        ->and($cost->total())->toBe(37.05);
});

test('returns an unknown cost when pricing is missing', function () {
    $cost = calculatorFor()->calculate(new Usage(promptTokens: 1000), 'openai', 'no-such-model');

    expect($cost->isKnown())->toBeFalse()
        ->and($cost->total())->toBe(0.0);
});
