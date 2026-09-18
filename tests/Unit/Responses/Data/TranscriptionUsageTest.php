<?php

use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;

test('transcription usage to array extends the base usage array with the audio duration', function (): void {
    $usage = new TranscriptionUsage(14, 8, audioSeconds: 203.5);

    expect($usage->toArray())->toBe([
        'input_tokens' => 14,
        'output_tokens' => 8,
        'cache_read_input_tokens' => null,
        'cache_write_input_tokens' => null,
        'reasoning_tokens' => null,
        'audio_seconds' => 203.5,
    ]);
});

test('transcription usage can be created from a text usage', function (): void {
    $usage = TranscriptionUsage::from(new TextUsage(14, 8, 4, 2, 6), 203.5);

    expect($usage->toArray())->toBe([
        'input_tokens' => 14,
        'output_tokens' => 8,
        'cache_read_input_tokens' => 4,
        'cache_write_input_tokens' => 2,
        'reasoning_tokens' => 6,
        'audio_seconds' => 203.5,
    ]);
});
