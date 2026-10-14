<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\NeedsInput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SilentGeolocationTool implements NeedsInput, Tool
{
    public function description(): string
    {
        return 'Reads the browser location, unless it already has one.';
    }

    public function needsInput(JsonSchema $schema, Request $request): array
    {
        return ['latitude' => $schema->string()->required()];
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
