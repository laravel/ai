<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Fetches the current weather for a city.')]
class FakeMcpServerTool extends Tool
{
    public array $invocations = [];

    public function handle(Request $request): Response
    {
        $this->invocations[] = $request->all();

        return Response::text('Sunny in '.$request->get('city').'.');
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('The city to get the weather for.')
                ->required(),
            'units' => $schema->string()
                ->enum(['celsius', 'fahrenheit'])
                ->default('celsius'),
        ];
    }
}
