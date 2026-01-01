<?php

namespace Tests\Feature;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Tests\TestCase;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;

class FakeStructuredDataTest extends TestCase
{
    public function test_structured_data_can_be_faked(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $response = generate_fake_data_for_json_schema_type((new ObjectType([
            'name' => $schema->string()->required(),
            'age' => $schema->integer()->required()->min(1)->max(120),
            'address' => $schema->object([
                'line_one' => $schema->string(),
                'line_two' => $schema->string(),
            ])->withoutAdditionalProperties(),
            'role' => $schema->string()->required()->enum(['admin', 'editor']),
            'skills' => $schema->array()->required()->min(5)->items(
                $schema->string()->required(),
            ),
            'active' => $schema->boolean(),
        ]))->withoutAdditionalProperties());

        $this->assertTrue(is_string($response['name']));
        $this->assertTrue(is_numeric($response['age']));
        $this->assertTrue(is_array($response['address']));
        $this->assertTrue(in_array($response['role'], ['admin', 'editor']));
        $this->assertTrue(array_is_list($response['skills']));
        $this->assertTrue(is_bool($response['active']));
    }
}
