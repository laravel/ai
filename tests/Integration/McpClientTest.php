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

test('it connects to a live MCP server and fetches tools', function () {
    requiresMcpServerEverything();

    $client = Client::local('npx', ['-y', '@modelcontextprotocol/server-everything'])
        ->withTimeout(60)
        ->connect();

    try {
        $tools = $client->tools();

        expect($tools)->not->toBeEmpty();

        $names = $tools->map(fn ($tool) => $tool->name)->all();

        expect($names)->toContain('echo', 'get-sum');

        foreach ($tools as $tool) {
            expect(McpTool::supports($tool))->toBeTrue();

            $wrapped = new McpTool($tool);

            expect($wrapped->name())->toStartWith('mcp_tools_');

            // Every live tool schema normalizes into the deserializable subset without throwing.
            expect($wrapped->schema(new JsonSchemaTypeFactory))->toBeArray();
        }
    } finally {
        $client->disconnect();
    }
});

test('an agent uses live MCP tools and responds correctly', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);
    requiresMcpServerEverything();

    $client = Client::local('npx', ['-y', '@modelcontextprotocol/server-everything'])
        ->withTimeout(60)
        ->connect();

    try {
        $response = agent(
            instructions: 'You echo text for the user. Always call the echo tool with the exact text the user provides, then reply with the tool output verbatim.',
            tools: $client->tools()->all(),
        )->prompt(
            "Echo the text 'hello-mcp-123' using the echo tool.",
            provider: $provider,
            model: $model,
        );

        expect($response->toolCalls)->not->toBeEmpty()
            ->and($response->toolCalls->contains(fn ($call) => $call->name === 'mcp_tools_echo'))->toBeTrue()
            ->and($response->text)->toContain('hello-mcp-123');
    } finally {
        $client->disconnect();
    }
})->with('agent-providers');
