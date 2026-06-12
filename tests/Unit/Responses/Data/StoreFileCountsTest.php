<?php

use Laravel\Ai\Responses\Data\StoreFileCounts;

test('store file counts stores completed pending and failed counts', function () {
    $counts = new StoreFileCounts(10, 5, 2);

    expect($counts->completed)->toBe(10)
        ->and($counts->pending)->toBe(5)
        ->and($counts->failed)->toBe(2);
});

test('store file counts to array returns all counts', function () {
    $counts = new StoreFileCounts(1, 0, 0);

    $array = $counts->toArray();

    expect($array)->toBe([
        'completed' => 1,
        'pending' => 0,
        'failed' => 0,
    ]);
});

test('store file counts json serialize returns to array', function () {
    $counts = new StoreFileCounts(5, 5, 5);

    expect($counts->jsonSerialize())->toBe($counts->toArray());
});
