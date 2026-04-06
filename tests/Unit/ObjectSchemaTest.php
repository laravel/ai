<?php

namespace Tests\Unit;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Tests\TestCase;

class ObjectSchemaTest extends TestCase
{
    public function test_nested_objects_include_additional_properties_false(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'name' => $schema->string()->required(),
            'address' => $schema->object([
                'street' => $schema->string()->required(),
                'city' => $schema->string()->required(),
            ])->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['address']['additionalProperties']);
    }

    public function test_objects_nested_in_arrays_include_additional_properties_false(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'items' => $schema->array()->items(
                $schema->object([
                    'action' => $schema->string()->required(),
                    'amount' => $schema->integer()->required(),
                ])
            )->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['items']['items']['additionalProperties']);
    }

    public function test_deeply_nested_objects_include_additional_properties_false(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'user' => $schema->object([
                'name' => $schema->string()->required(),
                'contact' => $schema->object([
                    'email' => $schema->string()->required(),
                    'phone' => $schema->string()->required(),
                ])->required(),
            ])->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['user']['additionalProperties']);
        $this->assertFalse($result['properties']['user']['properties']['contact']['additionalProperties']);
    }

    public function test_objects_in_nested_arrays_include_additional_properties_false(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'matrix' => $schema->array()->items(
                $schema->array()->items(
                    $schema->object([
                        'value' => $schema->integer()->required(),
                    ])
                )
            )->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['properties']['matrix']['items']['items']['additionalProperties']);
    }

    public function test_nullable_nested_objects_include_additional_properties_false(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'name' => $schema->string()->required(),
            'address' => $schema->object([
                'street' => $schema->string()->required(),
                'city' => $schema->string()->required(),
            ])->nullable(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['address']['additionalProperties']);
    }
}
