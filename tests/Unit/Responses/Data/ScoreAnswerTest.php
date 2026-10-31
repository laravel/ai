<?php

use Laravel\Ai\Responses\Data\ScoreAnswer;

test('score answer reports the most probable level', function (): void {
    $answer = new ScoreAnswer(1.99, [0 => 0.0, 1 => 0.01, 2 => 0.99], [0 => 'Low', 1 => 'Medium', 2 => 'High']);

    expect($answer->level())->toBe(2)
        ->and($answer->label())->toBe('High')
        ->and($answer->normalized())->toBe(0.995);
});

test('score answer rounds the score when no distribution was reported', function (): void {
    $answer = new ScoreAnswer(0.4, [], [0 => 'Low', 1 => 'Medium', 2 => 'High']);

    expect($answer->level())->toBe(0)
        ->and($answer->label())->toBe('Low');
});
