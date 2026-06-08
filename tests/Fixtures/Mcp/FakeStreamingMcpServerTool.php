<?php

namespace Tests\Fixtures\Mcp;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FakeStreamingMcpServerTool extends Tool
{
    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('processing/progress', ['step' => 1]);
        yield Response::text('First. ');
        yield 'Second. ';
        yield Response::make([Response::text('Third.')]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
