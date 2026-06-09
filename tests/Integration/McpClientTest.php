<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\McpTool;
use Laravel\Mcp\Client;

use function Laravel\Ai\agent;

function requiresMcpServerEverything(): void
{
    if (! is_executable(trim((string) shell_exec('command -v npx 2>/dev/null')))) {
        test()->markTestSkipped('Missing npx — skipping live MCP server test.');
    }
}

test('every weird MCP tool from the everything server works across providers', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);
    requiresMcpServerEverything();

    $client = Client::local('npx', ['-y', '@modelcontextprotocol/server-everything'])
        ->withTimeout(60)
        ->connect();

    try {
        $tools = $client->tools();

        expect($tools)->not->toBeEmpty();

        foreach ($tools as $tool) {
            expect((new McpTool($tool))->schema(new JsonSchemaTypeFactory))->toBeArray();
        }

        $response = agent(
            instructions: 'You echo text for the user. Always call the echo tool with the exact text provided, then reply with the tool output verbatim.',
            tools: $tools->all(),
        )->prompt(
            "Echo the text 'hello-mcp-123' using the echo tool.",
            provider: $provider,
            model: $model,
        );

        expect($response->toolCalls->contains(fn ($call) => $call->name === 'mcp_tools_echo'))->toBeTrue()
            ->and($response->text)->toContain('hello-mcp-123');
    } finally {
        $client->disconnect();
    }
})->with('agent-providers');
