<?php

namespace Tests\Unit\Gateway;

use Laravel\Ai\Gateway\Prism\SanitizedObjectSchema;
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

        $sanitized = SanitizedObjectSchema::sanitize($schema);

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

        $sanitized = SanitizedObjectSchema::sanitize($schema);

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

        $sanitized = SanitizedObjectSchema::sanitize($schema);

        $this->assertCount(2, $sanitized['anyOf']);
        $this->assertArrayNotHasKey('name', $sanitized['anyOf'][0]);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized['anyOf'][0]);
        $this->assertArrayNotHasKey('name', $sanitized['anyOf'][1]);
        $this->assertArrayNotHasKey('additionalProperties', $sanitized['anyOf'][1]);
    }
}
