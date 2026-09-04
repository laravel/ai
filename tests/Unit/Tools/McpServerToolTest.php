<?php

use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tests\Fixtures\Mcp\FakeArrayMcpServerTool;
use Tests\Fixtures\Mcp\FakeErroringMcpServerTool;
use Tests\Fixtures\Mcp\FakeMcpServerTool;
use Tests\Fixtures\Mcp\FakeStreamingMcpServerTool;
use Tests\Fixtures\Mcp\FakeStructuredMcpServerTool;

beforeEach(function (): void {
    Container::setInstance(new Container);
});

test('it detects mcp server tool primitives', function (): void {
    expect([
        McpServerTool::supports(new FakeMcpServerTool),
        McpServerTool::supports(new stdClass),
    ])->sequence(
        fn ($supports) => $supports->toBeTrue(),
        fn ($supports) => $supports->toBeFalse(),
    );
});

test('it exposes the tool name, description, and schema', function (): void {
    $tool = new McpServerTool(new FakeMcpServerTool);

    expect($tool->name())->toBe('fake-mcp-server-tool');
    expect($tool->description())->toBe('Fetches the current weather for a city.');

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema)->toMatchArray([
        'required' => ['city'],
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'The city to get the weather for.',
            ],
            'units' => [
                'type' => 'string',
                'enum' => ['celsius', 'fahrenheit'],
                'default' => 'celsius',
            ],
        ],
    ]);
});

test('it invokes the underlying tool and returns text content', function (): void {
    $serverTool = new FakeMcpServerTool;
    $tool = new McpServerTool($serverTool);

    $result = $tool->handle(new Request(['city' => 'Paris']));

    expect($result)->toBe('Sunny in Paris.');
    expect($serverTool->invocations)->toBe([['city' => 'Paris']]);
});

test('it serializes structured tool responses as json', function (): void {
    $tool = new McpServerTool(new FakeStructuredMcpServerTool);

    $result = $tool->handle(new Request(['city' => 'Paris']));

    expect($result)
        ->toBeJson()
        ->json()
        ->toMatchArray([
            'temperature' => 72,
            'conditions' => 'Sunny',
        ]);
});

test('it surfaces tool errors with the standard prefix', function (): void {
    $tool = new McpServerTool(new FakeErroringMcpServerTool);

    expect($tool->handle(new Request))->toBe('MCP tool error: Something went wrong.');
});

test('it returns only the final yielded response and ignores notifications and intermediate updates', function (): void {
    $tool = new McpServerTool(new FakeStreamingMcpServerTool);

    expect($tool->handle(new Request))->toBe('Third.');
});

test('it returns only the final item from an array response', function (): void {
    $tool = new McpServerTool(new FakeArrayMcpServerTool);

    expect($tool->handle(new Request))->toBe('Third.');
});

test('lazily yielded responses still resolve the scoped mcp request', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        public function handle(Laravel\Mcp\Request $request): Generator
        {
            yield Response::text('First. ');
            yield Response::text(Container::getInstance()->make(Laravel\Mcp\Request::class)->get('city'));
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    expect($tool->handle(new Request(['city' => 'Paris'])))->toBe('Paris');
});

test('the mcp request binding is cleared after the call', function (): void {
    $tool = new McpServerTool(new FakeMcpServerTool);

    $tool->handle(new Request(['city' => 'Paris']));

    expect(Container::getInstance()->bound(Laravel\Mcp\Request::class))->toBeFalse();
});

test('it includes app resource uri when tool returns text and ui resource link', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        public function handle(Laravel\Mcp\Request $request): mixed
        {
            return Response::make([
                Response::text('dashboard loaded.'),
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    $result = $tool->handle(new Request);

    expect($result)
        ->toBeJson()
        ->json()
        ->toBe([
            'text' => 'dashboard loaded.',
            'appResourceUri' => 'ui://resources/weather-dashboard-app',
        ]);
});

test('it resolves app resource uri regardless of content order', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(Laravel\Mcp\Request $request): array
        {
            return [
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
                Response::text('dashboard loaded.'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    expect($tool->handle(new Request))->json()->toBe([
        'text' => 'dashboard loaded.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});

test('it ignores notifications when resolving app resource uri', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        public function handle(Laravel\Mcp\Request $request): Generator
        {
            yield Response::notification('processing/progress', ['step' => 1]);
            yield Response::text('dashboard loaded.');
            yield Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app');
            yield Response::notification('processing/progress', ['step' => 2]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    expect($tool->handle(new Request))->json()->toBe([
        'text' => 'dashboard loaded.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});

test('it ignores non-ui resource links for app rendering', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(Laravel\Mcp\Request $request): array
        {
            return [
                Response::text('dashboard loaded.'),
                Response::resourceLink('https://example.com/other', 'other'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    expect($tool->handle(new Request))->toBe('https://example.com/other');
});

test('it preserves error prefix when app resource is present', function (): void {
    $tool = new McpServerTool(new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(Laravel\Mcp\Request $request): array
        {
            return [
                Response::error('Something went wrong.'),
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    });

    expect($tool->handle(new Request))->json()->toBe([
        'text' => 'MCP tool error: Something went wrong.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});
