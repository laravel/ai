<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Mcp\FakeMcpClient;
use Tests\Fixtures\Mcp\FakeMcpTool;
use Tests\Fixtures\Mcp\FakeMcpToolResult;

test('it detects mcp client tool primitives', function () {
    expect([
        McpTool::supports(mcpTool(new FakeMcpClient)),
        McpTool::supports(new stdClass),
    ])->sequence(
        fn ($supports) => $supports->toBeTrue(),
        fn ($supports) => $supports->toBeFalse(),
    );
});

test('it does not own the mcp client lifecycle', function () {
    $client = new FakeMcpClient;

    new McpTool(mcpTool($client));

    expect($client)->toHaveProperties([
        'connects' => 0,
        'disconnects' => 0,
    ]);
});

test('it translates mcp input schemas to laravel tool schemas', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Search query.',
                'enum' => ['name', 'email'],
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum result count.',
                'minimum' => 1,
                'maximum' => 10,
            ],
            'filters' => [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Only active records.',
                    ],
                ],
                'required' => ['active'],
            ],
            'tags' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['vip', 'trial'],
                ],
            ],
            'email' => [
                'type' => ['string', 'null'],
                'format' => 'email',
            ],
        ],
        'required' => ['query', 'filters'],
    ]));

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema)->toMatchArray([
        'required' => ['query', 'filters'],
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Search query.',
                'enum' => ['name', 'email'],
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum result count.',
                'minimum' => 1,
                'maximum' => 10,
            ],
            'filters' => [
                'type' => 'object',
                'required' => ['active'],
                'additionalProperties' => false,
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Only active records.',
                    ],
                ],
            ],
            'tags' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['vip', 'trial'],
                ],
            ],
            'email' => [
                'type' => ['string', 'null'],
                'format' => 'email',
            ],
        ],
    ]);
});

test('it resolves $ref against $defs and merges sibling keys', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'type' => 'object',
        'properties' => [
            'address' => [
                '$ref' => '#/$defs/Address',
                'description' => 'Shipping address.',
            ],
        ],
        'required' => ['address'],
        '$defs' => [
            'Address' => [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string'],
                ],
                'required' => ['city'],
            ],
        ],
    ]));

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties']['address'])->toMatchArray([
        'type' => 'object',
        'description' => 'Shipping address.',
        'required' => ['city'],
        'properties' => [
            'city' => ['type' => 'string'],
        ],
    ]);
});

test('it drops an unresolvable $ref instead of failing the whole tool', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'type' => 'object',
        'properties' => [
            'address' => ['$ref' => '#/$defs/Missing', 'description' => 'Shipping address.'],
            'name' => ['type' => 'string'],
        ],
        'required' => ['name'],
    ]));

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties'])->toHaveKeys(['address', 'name']);
    expect($schema['properties']['address'])->toMatchArray([
        'type' => 'string',
        'description' => 'Shipping address.',
    ]);
    expect($schema['properties']['name'])->toMatchArray(['type' => 'string']);
});

test('it keeps the properties of a nullable object root', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'anyOf' => [
            [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'],
            ],
            ['type' => 'null'],
        ],
    ]));

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties'])->toHaveKey('query');
    expect($schema['properties']['query'])->toMatchArray(['type' => 'string']);
});

test('it degrades to no parameters instead of throwing on a schema the deserializer rejects', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'type' => 'object',
        'properties' => [
            // A non-numeric numeric bound makes the deserializer throw; the tool must not.
            'amount' => ['type' => 'integer', 'minimum' => 'not-a-number'],
        ],
    ]));

    expect($tool->schema(new JsonSchemaTypeFactory))->toBe([]);
});

test('it marks fields nullable when anyOf or oneOf lists null after the typed branch', function () {
    $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
        'type' => 'object',
        'properties' => [
            'nickname' => [
                'anyOf' => [
                    ['type' => 'string', 'minLength' => 1],
                    ['type' => 'null'],
                ],
            ],
            'count' => [
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 0],
                    ['type' => 'null'],
                ],
            ],
        ],
    ]));

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($schema['properties']['nickname'])->toMatchArray([
        'type' => ['string', 'null'],
        'minLength' => 1,
    ]);

    expect($schema['properties']['count'])->toMatchArray([
        'type' => ['integer', 'null'],
        'minimum' => 0,
    ]);
});

test('it returns text content from successful mcp calls', function () {
    $client = new FakeMcpClient;
    $tool = new McpTool(mcpTool($client, name: 'search'));

    $client->results['search'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Found results.'],
    ], false);

    expect($tool->handle(new Request(['query' => 'laravel'])))->toBe('Found results.');

    expect($client)->toHaveProperty(
        'toolCalls',
        [
            ['name' => 'search', 'arguments' => ['query' => 'laravel']],
        ]
    );
});

test('it prefers structured content from successful mcp calls', function () {
    $client = new FakeMcpClient;
    $tool = new McpTool(mcpTool($client, name: 'lookup'));

    $client->results['lookup'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'ignored'],
    ], false, [
        'id' => 1,
        'name' => 'Taylor',
    ]);

    expect($tool->handle(new Request(['id' => 1])))
        ->toBeJson()
        ->json()
        ->toMatchArray([
            'id' => 1,
            'name' => 'Taylor',
        ]);
});

test('it surfaces application level mcp errors as tool output', function () {
    $client = new FakeMcpClient;
    $tool = new McpTool(mcpTool($client, name: 'lookup'));

    $client->results['lookup'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Record not found.'],
    ], true);

    expect($tool->handle(new Request(['id' => 1])))->toBe('MCP tool error: Record not found.');
});

test('it falls back to title then name when description is null', function () {
    $withTitle = new McpTool(mcpTool(new FakeMcpClient, name: 'lookup', title: 'Lookup record', description: null));
    $withoutTitle = new McpTool(mcpTool(new FakeMcpClient, name: 'fallback_name', title: null, description: null));

    expect([
        $withTitle->description(),
        $withoutTitle->description(),
    ])->sequence(
        fn ($description) => $description->toBe('Lookup record'),
        fn ($description) => $description->toBe('fallback_name'),
    );
});

/**
 * @param  array<string, mixed>  $inputSchema
 */
function mcpTool(
    FakeMcpClient $client,
    string $name = 'tool',
    ?string $title = null,
    ?string $description = 'Tool description.',
    array $inputSchema = ['type' => 'object', 'properties' => []],
): FakeMcpTool {
    return new FakeMcpTool($client, $name, $title, $description, $inputSchema);
}
