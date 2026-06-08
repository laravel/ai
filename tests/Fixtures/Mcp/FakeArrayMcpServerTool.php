<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FakeArrayMcpServerTool extends Tool
{
    /**
     * @return array<int, Response|string>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text('First. '),
            'Second. ',
            Response::text('Third.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
