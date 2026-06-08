<?php

use Illuminate\Container\Container;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Mcp\FakeArrayMcpServerTool;
use Tests\Fixtures\Mcp\FakeErroringMcpServerTool;
use Tests\Fixtures\Mcp\FakeMcpServerTool;
use Tests\Fixtures\Mcp\FakeStreamingMcpServerTool;
use Tests\Fixtures\Mcp\FakeStructuredMcpServerTool;

beforeEach(function () {
    Container::setInstance(new Container);
});

test('it detects mcp server tool primitives', function () {
    expect([
        McpServerTool::supports(new FakeMcpServerTool),
        McpServerTool::supports(new stdClass),
    ])->sequence(
        fn ($supports) => $supports->toBeTrue(),
        fn ($supports) => $supports->toBeFalse(),
    );
});

test('it exposes the tool name, description, and schema', function () {
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

test('it invokes the underlying tool and returns text content', function () {
    $serverTool = new FakeMcpServerTool;
    $tool = new McpServerTool($serverTool);

    $result = $tool->handle(new Request(['city' => 'Paris']));

    expect($result)->toBe('Sunny in Paris.');
    expect($serverTool->invocations)->toBe([['city' => 'Paris']]);
});

test('it serializes structured tool responses as json', function () {
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

test('it surfaces tool errors with the standard prefix', function () {
    $tool = new McpServerTool(new FakeErroringMcpServerTool);

    expect($tool->handle(new Request))->toBe('MCP tool error: Something went wrong.');
});

test('it returns only the final yielded response and ignores notifications and intermediate updates', function () {
    $tool = new McpServerTool(new FakeStreamingMcpServerTool);

    expect($tool->handle(new Request))->toBe('Third.');
});

test('it returns only the final item from an array response', function () {
    $tool = new McpServerTool(new FakeArrayMcpServerTool);

    expect($tool->handle(new Request))->toBe('Third.');
});

test('the mcp request binding is cleared after the call', function () {
    $tool = new McpServerTool(new FakeMcpServerTool);

    $tool->handle(new Request(['city' => 'Paris']));

    expect(Container::getInstance()->bound('Laravel\\Mcp\\Request'))->toBeFalse();
});
