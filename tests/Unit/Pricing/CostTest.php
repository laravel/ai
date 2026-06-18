<?php

use Laravel\Ai\Responses\Data\Cost;

test('total sums every component', function () {
    $cost = new Cost(input: 0.01, output: 0.02, cacheRead: 0.001, cacheWrite: 0.003, reasoning: 0.005);

    expect($cost->total())->toBe(0.039)
        ->and($cost->isKnown())->toBeTrue();
});

test('unknown cost has zero total and is flagged', function () {
    $cost = Cost::unknown();

    expect($cost->total())->toBe(0.0)
        ->and($cost->isKnown())->toBeFalse();
});

test('breakdown exposes each component', function () {
    $cost = new Cost(input: 0.01, output: 0.02, cacheRead: 0.001, cacheWrite: 0.003, reasoning: 0.005);

    expect($cost->breakdown())->toBe([
        'input' => 0.01,
        'output' => 0.02,
        'cache_read' => 0.001,
        'cache_write' => 0.003,
        'reasoning' => 0.005,
    ]);
});

test('format renders USD with a dollar sign and other currencies with a suffix', function () {
    expect((new Cost(input: 1.5))->format(2))->toBe('$1.50')
        ->and((new Cost(input: 1.5, currency: 'EUR'))->format(2))->toBe('1.50 EUR');
});

test('add combines two costs and degrades known when either is unknown', function () {
    $a = new Cost(input: 0.01, output: 0.02);
    $b = new Cost(input: 0.03, output: 0.04);

    $sum = $a->add($b);

    expect($sum->input)->toBe(0.04)
        ->and($sum->output)->toBe(0.06)
        ->and($sum->isKnown())->toBeTrue()
        ->and($a->add(Cost::unknown())->isKnown())->toBeFalse();
});

test('serializes to an array with total and breakdown', function () {
    $cost = new Cost(input: 0.01, output: 0.02);

    expect($cost->toArray())->toMatchArray([
        'total' => 0.03,
        'currency' => 'USD',
        'known' => true,
        'input' => 0.01,
        'output' => 0.02,
    ])->and($cost->jsonSerialize())->toBe($cost->toArray());
});
