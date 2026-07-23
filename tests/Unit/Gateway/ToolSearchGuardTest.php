<?php

use Laravel\Ai\Gateway\Gemini\Concerns\MapsTools as GeminiMapsTools;
use Laravel\Ai\Gateway\Xai\Concerns\MapsTools as XaiMapsTools;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Tests\Fixtures\Tools\DeferredTool;
use Tests\Fixtures\Tools\NonStrictTool;

function toolSearchGuardProvider(string $name): Provider
{
    return new class($name) extends Provider
    {
        public function __construct(protected string $providerName)
        {
            //
        }

        public function name(): string
        {
            return $this->providerName;
        }
    };
}

test('xAI rejects a ToolSearch tool instead of silently dropping its nested tools', function () {
    $mapper = new class
    {
        use XaiMapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };

    expect(fn () => $mapper->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        toolSearchGuardProvider('xai'),
    ))->toThrow(RuntimeException::class, 'does not support tool search');
});

test('Gemini rejects a ToolSearch tool instead of silently dropping its nested tools', function () {
    $mapper = new class
    {
        use GeminiMapsTools;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };

    expect(fn () => $mapper->map(
        [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
        toolSearchGuardProvider('gemini'),
    ))->toThrow(RuntimeException::class, 'does not support tool search');
});
