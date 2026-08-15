<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeferredTool implements Tool
{
    public function description(): string
    {
        return 'A deferred tool whose definition is loaded on demand.';
    }

    public function handle(Request $request): string
    {
        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
