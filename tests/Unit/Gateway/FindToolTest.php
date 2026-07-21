<?php

use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function toolFinder(): object
{
    return new class
    {
        use InvokesTools;

        public function find(string $name, array $tools): mixed
        {
            return $this->findTool($name, $tools);
        }
    };
}

test('finds a top-level tool by name', function () {
    $tool = new NonStrictTool;

    expect(toolFinder()->find('NonStrictTool', [$tool]))->toBe($tool);
});

test('finds a deferred tool by name', function () {
    $deferred = new DeferredTool;

    $found = toolFinder()->find('DeferredTool', [
        new NonStrictTool,
        $deferred,
    ]);

    expect($found)->toBe($deferred);
});

test('returns null when the tool is not present anywhere', function () {
    $found = toolFinder()->find('Missing', [
        new NonStrictTool,
        new DeferredTool,
    ]);

    expect($found)->toBeNull();
});
