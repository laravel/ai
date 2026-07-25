<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;

test('structured data can be faked', function (): void {
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

    expect($response['name'])->toBeString()
        ->and($response['age'])->toBeNumeric()
        ->and($response['address'])->toBeArray()
        ->and(['admin', 'editor'])->toContain($response['role'])
        ->and($response['skills'])->toBeList()
        ->and($response['active'])->toBeBool();
});
