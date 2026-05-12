<?php

use Laravel\Ai\Attributes\Strict;
use Tests\Fixtures\Tools\NonStrictTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

test('isAppliedTo returns true when target has the attribute', function () {
    expect(Strict::isAppliedTo(new RandomNumberGenerator))->toBeTrue();
});

test('isAppliedTo returns false when target does not have the attribute', function () {
    expect(Strict::isAppliedTo(new NonStrictTool))->toBeFalse();
});

test('isAppliedTo returns false when target is null', function () {
    expect(Strict::isAppliedTo(null))->toBeFalse();
});
