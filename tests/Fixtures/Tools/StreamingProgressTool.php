<?php

namespace Tests\Fixtures\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\StreamableTool;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;

#[Strict]
class StreamingProgressTool implements StreamableTool
{
    public function description(): string
    {
        return 'Streams a progress event before returning a final result.';
    }

    public function handle(Request $request): string
    {
        return 'streaming result';
    }

    public function streamHandle(Request $request): Generator
    {
        yield new TextDelta('evt_progress', 'msg_progress', 'streaming progress', time());

        return 'streaming result';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
