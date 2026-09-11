<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Interactive;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SilentGeolocationTool implements Interactive, Tool
{
    public function description(): string
    {
        return 'Reads the browser location, unless it already has one.';
    }

    public function ask(Request $request): ?array
    {
        return $request['latitude'] === null ? [] : null;
    }

    public function handle(Request $request): Stringable|string
    {
        return 'at: '.$request['latitude'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
