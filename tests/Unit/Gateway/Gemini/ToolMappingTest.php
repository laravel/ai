<?php

use Laravel\Ai\Gateway\Gemini\Concerns\MapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\NonStrictTool;

function geminiToolMapper(): object
{
    return new class
    {
        use MapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };
}

function geminiToolMappingProvider(): Provider
{
    return new class extends Provider
    {
        public function __construct()
        {
            //
        }

        public function name(): string
        {
            return 'gemini';
        }
    };
}

test('throws for a provider tool Gemini cannot map instead of emitting an empty entry', function () {
    expect(fn () => geminiToolMapper()->map(
        [new ToolSearch(tools: [new NonStrictTool])],
        geminiToolMappingProvider(),
    ))->toThrow(RuntimeException::class, 'does not support the [ToolSearch] tool');
});
