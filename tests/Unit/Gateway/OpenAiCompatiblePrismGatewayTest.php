<?php

namespace Tests\Unit\Gateway;

use Laravel\Ai\Gateway\Prism\OpenAiCompatiblePrismTool;
use PHPUnit\Framework\TestCase;

class OpenAiCompatiblePrismGatewayTest extends TestCase
{
    public function test_it_strips_unsupported_fields_from_schema()
    {
        $schema = [
            'name' => 'root_schema',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'field_one' => [
                    'type' => 'string',
                    'name' => 'field_one_name',
                ],
                'nested' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'inner' => [
                            'type' => 'integer',
                            'name' => 'inner_name',
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = OpenAiCompatiblePrismTool::sanitize($schema);

        $this->assertArrayNotHasKey('name', $sanitized);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized);

        $this->assertArrayHasKey('properties', $sanitized);
        $this->assertArrayNotHasKey('name', $sanitized['properties']['field_one']);

        $this->assertArrayNotHasKey('additionalProperties', $sanitized['properties']['nested']);
        $this->assertArrayNotHasKey('name', $sanitized['properties']['nested']['properties']['inner']);
    }

    public function test_it_sanitizes_array_items()
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'name' => 'item_name',
                'additionalProperties' => false,
                'properties' => [
                    'id' => ['type' => 'integer', 'name' => 'id_name'],
                ],
            ],
        ];

        $sanitized = OpenAiCompatiblePrismTool::sanitize($schema);

        $this->assertArrayHasKey('items', $sanitized);
        $this->assertArrayNotHasKey('name', $sanitized['items']);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized['items']);
        $this->assertArrayNotHasKey('name', $sanitized['items']['properties']['id']);
    }

    public function test_it_sanitizes_any_of_structures()
    {
        $schema = [
            'anyOf' => [
                [
                    'type' => 'object',
                    'name' => 'variant_1',
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'name' => 'variant_2',
                    'additionalProperties' => false,
                ],
            ],
        ];

        $sanitized = OpenAiCompatiblePrismTool::sanitize($schema);

        $this->assertCount(2, $sanitized['anyOf']);
        $this->assertArrayNotHasKey('name', $sanitized['anyOf'][0]);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized['anyOf'][0]);
        $this->assertArrayNotHasKey('name', $sanitized['anyOf'][1]);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized['anyOf'][1]);
    }

    public function test_tool_returns_flat_properties_from_schema()
    {
        $tool = (new OpenAiCompatiblePrismTool)
            ->as('test_tool')
            ->for('A test tool')
            ->withSanitizedSchema([
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results'],
                ],
                'required' => ['query'],
            ])
            ->using(fn () => 'ok');

        $params = $tool->parametersAsArray();

        $this->assertArrayHasKey('query', $params);
        $this->assertArrayHasKey('limit', $params);
        $this->assertArrayNotHasKey('schema_definition', $params);
        $this->assertSame(['query'], $tool->requiredParameters());
        $this->assertTrue($tool->hasParameters());
    }

    public function test_tool_handles_empty_schema()
    {
        $tool = (new OpenAiCompatiblePrismTool)
            ->as('empty_tool')
            ->for('No parameters')
            ->withSanitizedSchema([])
            ->using(fn () => 'ok');

        $this->assertFalse($tool->hasParameters());
        $this->assertEmpty($tool->parametersAsArray());
        $this->assertEmpty($tool->requiredParameters());
    }

    public function test_tool_strips_unsupported_fields_from_properties()
    {
        $tool = (new OpenAiCompatiblePrismTool)
            ->as('sanitized_tool')
            ->for('Tool with dirty schema')
            ->withSanitizedSchema([
                'type' => 'object',
                'name' => 'should_be_stripped',
                'additionalProperties' => false,
                'properties' => [
                    'input' => [
                        'type' => 'string',
                        'name' => 'also_stripped',
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['input'],
            ])
            ->using(fn () => 'ok');

        $params = $tool->parametersAsArray();

        $this->assertArrayNotHasKey('name', $params['input']);
        $this->assertArrayNotHasKey('additionalProperties', $params['input']);
        $this->assertSame('string', $params['input']['type']);
    }

    public function test_tool_produces_correct_groq_toolmap_structure()
    {
        $tool = (new OpenAiCompatiblePrismTool)
            ->as('search')
            ->for('Search the web')
            ->withSanitizedSchema([
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query'],
                ],
                'required' => ['query'],
            ])
            ->using(fn () => 'ok');

        // Simulate what Groq ToolMap::Map() does
        $mapped = [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->parametersAsArray(),
                    'required' => $tool->requiredParameters(),
                ],
            ],
        ];

        $this->assertSame('search', $mapped['function']['name']);
        $this->assertArrayHasKey('query', $mapped['function']['parameters']['properties']);
        $this->assertSame('string', $mapped['function']['parameters']['properties']['query']['type']);
        $this->assertSame(['query'], $mapped['function']['parameters']['required']);
    }

    public function test_tool_handles_deeply_nested_schemas()
    {
        $tool = (new OpenAiCompatiblePrismTool)
            ->as('complex_tool')
            ->for('Complex nested schema')
            ->withSanitizedSchema([
                'type' => 'object',
                'properties' => [
                    'config' => [
                        'type' => 'object',
                        'name' => 'should_strip',
                        'additionalProperties' => false,
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'name' => 'strip_this_too',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'value' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'required' => ['config'],
            ])
            ->using(fn () => 'ok');

        $params = $tool->parametersAsArray();
        $config = $params['config'];

        $this->assertArrayNotHasKey('name', $config);
        $this->assertArrayNotHasKey('additionalProperties', $config);

        $items = $config['properties']['items']['items'];
        $this->assertArrayNotHasKey('name', $items);
        $this->assertArrayNotHasKey('additionalProperties', $items);
        $this->assertSame('string', $items['properties']['value']['type']);
    }
}
