<?php

use Laravel\Ai\Gateway\Prism\PrismUsage;
use Laravel\Ai\Responses\Data\Usage;
use Prism\Prism\ValueObjects\Usage as PrismUsageValueObject;

test('converts prism usage to laravel usage', function () {
    $prismUsage = new PrismUsageValueObject(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 10,
        cacheReadInputTokens: 5,
        thoughtTokens: 20,
    );

    $usage = PrismUsage::toLaravelUsage($prismUsage);

    expect($usage)->toBeInstanceOf(Usage::class)
        ->and($usage->promptTokens)->toEqual(100)
        ->and($usage->completionTokens)->toEqual(50)
        ->and($usage->cacheWriteInputTokens)->toEqual(10)
        ->and($usage->cacheReadInputTokens)->toEqual(5)
        ->and($usage->reasoningTokens)->toEqual(20);
});

test('handles null usage', function () {
    $usage = PrismUsage::toLaravelUsage(null);

    expect($usage)->toBeInstanceOf(Usage::class)
        ->and($usage->promptTokens)->toEqual(0)
        ->and($usage->completionTokens)->toEqual(0)
        ->and($usage->cacheWriteInputTokens)->toEqual(0)
        ->and($usage->cacheReadInputTokens)->toEqual(0)
        ->and($usage->reasoningTokens)->toEqual(0);
});
