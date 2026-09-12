<?php

use Laravel\Ai\Responses\Data\AudioUsage;
use Laravel\Ai\Responses\Data\Usage;

test('audio usage extends the base usage array with the duration', function (): void {
    expect((new AudioUsage(10, 5, 2, 3, 1, 203.0))->toArray())->toBe([
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'cache_write_input_tokens' => 2,
        'cache_read_input_tokens' => 3,
        'reasoning_tokens' => 1,
        'duration_seconds' => 203.0,
    ]);
});

test('adding audio usage sums the tokens and the durations', function (): void {
    $usage = (new AudioUsage(10, 5, 2, 3, 1, 3.0))->add(new AudioUsage(1, 2, 3, 4, 5, 2.0));

    expect($usage)->toBeInstanceOf(AudioUsage::class)
        ->and($usage->toArray())->toBe([
            'prompt_tokens' => 11,
            'completion_tokens' => 7,
            'cache_write_input_tokens' => 5,
            'cache_read_input_tokens' => 7,
            'reasoning_tokens' => 6,
            'duration_seconds' => 5.0,
        ]);
});

test('adding plain usage leaves the duration untouched', function (): void {
    expect((new AudioUsage(durationSeconds: 3.0))->add(new Usage(1, 2))->durationSeconds)->toBe(3.0);
});
