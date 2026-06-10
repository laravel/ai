<?php

use Laravel\Ai\Attributes\Deferred;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

test('isAppliedTo returns true when target has the attribute', function () {
    expect(Deferred::isAppliedTo(new DeferredTool))->toBeTrue();
});

test('isAppliedTo returns false when target does not have the attribute', function () {
    expect(Deferred::isAppliedTo(new NonStrictTool))->toBeFalse();
});

test('isAppliedTo returns false when target is null', function () {
    expect(Deferred::isAppliedTo(null))->toBeFalse();
});
