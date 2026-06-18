<?php

use Laravel\Ai\Pricing\BudgetMeter;
use Laravel\Ai\Responses\Data\Cost;

test('records and accumulates spend per bucket', function () {
    $meter = new BudgetMeter;

    $meter->record('a', new Cost(input: 0.01));
    $meter->record('a', new Cost(output: 0.02));
    $meter->record('b', new Cost(input: 0.05));

    expect($meter->spent('a'))->toBe(0.03)
        ->and($meter->spent('b'))->toBe(0.05)
        ->and($meter->spent('missing'))->toBe(0.0)
        ->and($meter->total())->toBe(0.08);
});

test('reset clears a single bucket or everything', function () {
    $meter = new BudgetMeter;
    $meter->record('a', new Cost(input: 0.01));
    $meter->record('b', new Cost(input: 0.02));

    $meter->reset('a');
    expect($meter->spent('a'))->toBe(0.0)
        ->and($meter->spent('b'))->toBe(0.02);

    $meter->reset();
    expect($meter->total())->toBe(0.0);
});
