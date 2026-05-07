<?php

use Laravel\Ai\Responses\Data\TranscriptionSegment;

test('transcription segment stores text speaker and timestamps', function () {
    $segment = new TranscriptionSegment('Hello world', 'Speaker 1', 0.0, 2.5);

    expect($segment->text)->toBe('Hello world')
        ->and($segment->speaker)->toBe('Speaker 1')
        ->and($segment->startSeconds)->toBe(0.0)
        ->and($segment->endSeconds)->toBe(2.5);
});

test('transcription segment to array returns all properties', function () {
    $segment = new TranscriptionSegment('Test speech', 'Speaker A', 1.0, 3.5);

    $array = $segment->toArray();

    expect($array['text'])->toBe('Test speech')
        ->and($array['speaker'])->toBe('Speaker A')
        ->and($array['start_seconds'])->toBe(1.0)
        ->and($array['end_seconds'])->toBe(3.5);
});

test('transcription segment json serialize returns to array', function () {
    $segment = new TranscriptionSegment('Content', 'Speaker', 5.0, 10.0);

    $json = $segment->jsonSerialize();

    expect($json['text'])->toBe('Content')
        ->and($json['speaker'])->toBe('Speaker');
});
