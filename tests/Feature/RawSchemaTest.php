<?php

namespace Tests\Feature;

use Laravel\Ai\RawSchema;
use Tests\TestCase;

class RawSchemaTest extends TestCase
{
    public function test_it_can_be_created_from_array(): void
    {
        $schema = RawSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'symbol' => ['type' => 'string'],
            ],
            'required' => ['symbol'],
            'additionalProperties' => false,
        ], 'chemical_symbol');

        $this->assertSame('chemical_symbol', $schema->name());
        $this->assertSame('chemical_symbol', $schema->toSchema()['name']);
        $this->assertSame('object', $schema->toSchema()['type']);
    }

    public function test_it_can_be_created_from_json(): void
    {
        $schema = RawSchema::fromJson(json_encode([
            'type' => 'object',
            'properties' => [
                'symbol' => ['type' => 'string'],
            ],
            'required' => ['symbol'],
            'additionalProperties' => false,
        ]));

        $this->assertSame('schema_definition', $schema->name());
        $this->assertSame('object', $schema->toSchema()['type']);
        $this->assertIsArray($schema->definition()['properties']);
    }

    public function test_it_can_be_created_from_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-schema-');

        file_put_contents($path, json_encode([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ]));

        try {
            $schema = RawSchema::fromFile($path, 'person');

            $this->assertSame('person', $schema->name());
            $this->assertSame('person', $schema->toSchema()['name']);
            $this->assertSame('object', $schema->toSchema()['type']);
        } finally {
            @unlink($path);
        }
    }
}
