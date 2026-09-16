<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Interactive;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GuardedGeolocationTool implements Approvable, Interactive, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Reads the browser location unless it already has one, and always asks before using it.';
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
