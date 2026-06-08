<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class FakeStructuredMcpServerTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'temperature' => 72,
            'conditions' => 'Sunny',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
        ];
    }
}
