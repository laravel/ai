<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SideEffectRecorder implements Tool
{
    public static int $invocations = 0;

    public function description(): string
    {
        return 'Records an irreversible side effect.';
    }

    public function handle(Request $request): string
    {
        static::$invocations++;

        return 'recorded';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
