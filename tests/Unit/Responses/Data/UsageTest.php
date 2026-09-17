<?php

use Laravel\Ai\Responses\Data\Usage;

test('usage defaults to zero tokens and unreported details', function (): void {
    $usage = new Usage;

    expect($usage->inputTokens)->toBe(0)
        ->and($usage->outputTokens)->toBe(0)
        ->and($usage->cacheReadInputTokens)->toBeNull()
        ->and($usage->cacheWriteInputTokens)->toBeNull()
        ->and($usage->reasoningTokens)->toBeNull()
        ->and($usage->raw)->toBe([]);
});

test('usage derives totals from the inclusive input and output counts', function (): void {
    $usage = new Usage(100, 50, cacheReadInputTokens: 30, cacheWriteInputTokens: 20, reasoningTokens: 5);

    expect($usage->totalTokens())->toBe(150)
        ->and($usage->uncachedInputTokens())->toBe(50);
});

test('usage treats unreported cache counts as zero when deriving the uncached input', function (): void {
    expect((new Usage(100, 50))->uncachedInputTokens())->toBe(100);
});

test('usage add sums every count and drops the raw payload', function (): void {
    $combined = (new Usage(100, 50, 10, 25, 5, ['a' => 1]))->add(new Usage(50, 25, 5, 10, 0, ['b' => 2]));

    expect($combined)->toEqual(new Usage(150, 75, 15, 35, 5));
});

test('usage add keeps a detail null only when neither side reported it', function (): void {
    $combined = (new Usage(1, 1, cacheReadInputTokens: 7))->add(new Usage(1, 1, reasoningTokens: 3));

    expect($combined->cacheReadInputTokens)->toBe(7)
        ->and($combined->reasoningTokens)->toBe(3)
        ->and($combined->cacheWriteInputTokens)->toBeNull();
});

test('usage to array serializes the counts without the raw payload', function (): void {
    $usage = new Usage(100, 50, 10, 25, null, ['input_tokens' => 100]);

    expect($usage->toArray())->toBe([
        'input_tokens' => 100,
        'output_tokens' => 50,
        'cache_read_input_tokens' => 10,
        'cache_write_input_tokens' => 25,
        'reasoning_tokens' => null,
    ])->and($usage->jsonSerialize())->toBe($usage->toArray());
});
