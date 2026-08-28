<?php

use Laravel\Ai\Responses\Data\AudioUsage;
use Laravel\Ai\Responses\Data\Usage;

test('audio usage defaults to zero duration', function (): void {
    expect((new AudioUsage)->durationSeconds)->toBe(0.0);
});

test('audio usage reports the duration alongside the token counts', function (): void {
    $usage = new AudioUsage(10, 5, 2, 203.0);

    expect($usage->promptTokens)->toBe(10)
        ->and($usage->completionTokens)->toBe(5)
        ->and($usage->reasoningTokens)->toBe(2)
        ->and($usage->durationSeconds)->toBe(203.0)
        ->and($usage->toArray())->toBe([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'reasoning_tokens' => 2,
            'duration_seconds' => 203.0,
        ]);
});

test('adding audio usage sums the durations', function (): void {
    $usage = (new AudioUsage(10, 5, 0, 3.0))->add(new AudioUsage(1, 2, 0, 2.0));

    expect($usage)->toBeInstanceOf(AudioUsage::class)
        ->and($usage->promptTokens)->toBe(11)
        ->and($usage->durationSeconds)->toBe(5.0);
});

test('adding plain usage leaves the duration untouched', function (): void {
    expect((new AudioUsage(0, 0, 0, 3.0))->add(new Usage(1, 2))->durationSeconds)->toBe(3.0);
});
