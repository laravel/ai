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
            return $this->resolveToolName($tool);
        }

        public function callFind(string $name, array $tools): ?Tool
        {
            return $this->findTool($name, $tools);
        }
    };
});

test('resolveToolName falls back to class basename when tool has no name() method', function () {
    expect($this->host->callResolve(new FixedNumberGenerator))->toBe('FixedNumberGenerator');
});

test('resolveToolName prefers the declared name() method when present', function () {
    expect($this->host->callResolve(new NamedTool('aliased_tool')))->toBe('aliased_tool');
});

test('findTool matches a tool by its declared name() when multiple share a class', function () {
    $tools = [
        new NamedTool('aliased_tool'),
        new NamedTool('other_aliased_tool'),
        new FixedNumberGenerator,
    ];

    expect($this->host->callFind('other_aliased_tool', $tools))->toBe($tools[1])
        ->and($this->host->callFind('FixedNumberGenerator', $tools))->toBe($tools[2])
        ->and($this->host->callFind('unknown', $tools))->toBeNull();
});

test('findTool returns null when no tool matches', function () {
    expect($this->host->callFind('missing', [new FixedNumberGenerator]))->toBeNull();
});
