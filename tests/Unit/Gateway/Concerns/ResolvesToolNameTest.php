<?php

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ResolvesToolName;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;

function resolverHost(): object
{
    return new class
    {
        use InvokesTools;
        use ResolvesToolName;

        public function __construct()
        {
            $this->initializeToolCallbacks();
        }

        public function callResolve(Tool $tool): string
        {
            return $this->getToolName($tool);
        }

        public function callFind(string $name, array $tools): ?Tool
        {
            return $this->findTool($name, $tools);
        }
    };
}

test('getToolName falls back to class basename when tool has no name() method', function () {
    $host = resolverHost();

    expect($host->callResolve(new FixedNumberGenerator))->toBe('FixedNumberGenerator');
});

test('getToolName prefers the declared name() method when present', function () {
    $host = resolverHost();

    expect($host->callResolve(new NamedTool('aliased_tool')))
        ->toBe('aliased_tool');
});

test('findTool matches a tool by its declared name() when multiple share a class', function () {
    $host = resolverHost();

    $tools = [
        new NamedTool('aliased_tool'),
        new NamedTool('other_aliased_tool'),
        new FixedNumberGenerator,
    ];

    expect($host->callFind('other_aliased_tool', $tools))->toBe($tools[1])
        ->and($host->callFind('FixedNumberGenerator', $tools))->toBe($tools[2])
        ->and($host->callFind('unknown', $tools))->toBeNull();
});

test('findTool returns null when no tool matches', function () {
    $host = resolverHost();

    expect($host->callFind('missing', [new FixedNumberGenerator]))->toBeNull();
});
