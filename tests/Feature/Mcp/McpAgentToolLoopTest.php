<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Mcp\McpManager;
use Laravel\Ai\Mcp\McpToolAlias;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);

    config(['ai.mcp.servers.test' => [
        'transport' => 'stdio',
        'command' => [PHP_BINARY, __DIR__.'/../../Fixtures/Mcp/stdio-server.php'],
        'env' => [],
        'timeout' => 30,
    ]]);
});

afterEach(function () {
    if (app()->resolved(McpManager::class)) {
        app(McpManager::class)->disconnectAll();
    }
});

function mcpToolCallResponse(string $name, string $arguments): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_mcp',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_mcp',
            'call_id' => 'call_mcp',
            'name' => $name,
            'arguments' => $arguments,
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

test('mcp tools surface to the provider as aliased function declarations', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('done'),
    ]);

    agent(
        instructions: 'use tools',
        mcpServers: ['test'],
    )->prompt('hello', provider: 'openai');

    $alias = McpToolAlias::make('test', 'say_hello');

    Http::assertSent(function (Request $request) use ($alias) {
        $body = json_decode($request->body(), true);
        $tools = $body['tools'] ?? [];

        $names = array_column($tools, 'name');

        return count($tools) === 2
            && in_array($alias, $names, true)
            && collect($tools)->every(
                fn ($tool) => is_string($tool['name'])
                    && strlen($tool['name']) <= 64
                    && ! str_contains($tool['name'], '.')
                    && preg_match('/^[A-Za-z0-9_-]+$/', $tool['name']) === 1
                    && ! array_key_exists('strict', $tool)
            );
    });
});

test('mcp tools forward the original raw schema to the provider', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('done'),
    ]);

    agent(
        instructions: 'use tools',
        mcpServers: ['test'],
    )->prompt('hello', provider: 'openai');

    $alias = McpToolAlias::make('test', 'say_hello');

    Http::assertSent(function (Request $request) use ($alias) {
        $body = json_decode($request->body(), true);
        $tools = $body['tools'] ?? [];

        $sayHello = collect($tools)->firstWhere('name', $alias);

        return $sayHello !== null
            && data_get($sayHello, 'parameters.type') === 'object'
            && data_get($sayHello, 'parameters.properties.name.type') === 'string'
            && data_get($sayHello, 'parameters.required.0') === 'name';
    });
});

test('the gateway invokes the mcp tool and forwards the result on follow up', function () {
    $alias = McpToolAlias::make('test', 'say_hello');

    Http::fake([
        '*' => Http::sequence([
            mcpToolCallResponse($alias, '{"name":"Taylor"}'),
            fakeOpenAiResponse('Said hello'),
        ]),
    ]);

    $response = agent(
        instructions: 'use tools',
        mcpServers: ['test'],
    )->prompt('hello', provider: 'openai');

    expect($response->text)->toBe('Said hello');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);
    $output = collect($followUpBody['input'] ?? [])
        ->firstWhere('type', 'function_call_output');

    expect($output)->not->toBeNull()
        ->and($output['call_id'])->toBe('call_mcp')
        ->and($output['output'])->toBe('Hello, Taylor');
});

test('regular tools and mcp tools coexist on the same agent', function () {
    Http::fake([
        '*' => fakeOpenAiResponse('done'),
    ]);

    $regular = new class implements \Laravel\Ai\Contracts\Tool
    {
        public function description(): string
        {
            return 'Regular fixed-number tool.';
        }

        public function handle(\Laravel\Ai\Tools\Request $request): string
        {
            return '42';
        }

        public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
        {
            return [];
        }
    };

    agent(
        instructions: 'use tools',
        tools: [$regular],
        mcpServers: ['test'],
    )->prompt('hello', provider: 'openai');

    $alias = McpToolAlias::make('test', 'say_hello');

    Http::assertSent(function (Request $request) use ($alias, $regular) {
        $body = json_decode($request->body(), true);
        $names = array_column($body['tools'] ?? [], 'name');

        return in_array($alias, $names, true)
            && in_array(class_basename($regular), $names, true);
    });
});
