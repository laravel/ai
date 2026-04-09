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

    expect($usage)->toBeInstanceOf(Usage::class);
    expect($usage->promptTokens)->toEqual(100);
    expect($usage->completionTokens)->toEqual(50);
    expect($usage->cacheWriteInputTokens)->toEqual(10);
    expect($usage->cacheReadInputTokens)->toEqual(5);
    expect($usage->reasoningTokens)->toEqual(20);
});

test('handles null usage', function () {
    $usage = PrismUsage::toLaravelUsage(null);

    expect($usage)->toBeInstanceOf(Usage::class);
    expect($usage->promptTokens)->toEqual(0);
    expect($usage->completionTokens)->toEqual(0);
    expect($usage->cacheWriteInputTokens)->toEqual(0);
    expect($usage->cacheReadInputTokens)->toEqual(0);
    expect($usage->reasoningTokens)->toEqual(0);
});
