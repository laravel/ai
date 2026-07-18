<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

test('nested objects include additional properties false', function (): void {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'name' => $schema->string()->required(),
        'address' => $schema->object([
            'street' => $schema->string()->required(),
            'city' => $schema->string()->required(),
        ])->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['address']['additionalProperties'])->toBeFalse();
});

test('objects nested in arrays include additional properties false', function (): void {
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

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['items']['items']['additionalProperties'])->toBeFalse();
});

test('deeply nested objects include additional properties false', function (): void {
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

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['user']['additionalProperties'])->toBeFalse()
        ->and($result['properties']['user']['properties']['contact']['additionalProperties'])->toBeFalse();
});

test('objects in nested arrays include additional properties false', function (): void {
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

    expect($result['properties']['matrix']['items']['items']['additionalProperties'])->toBeFalse();
});

test('nullable nested objects include additional properties false', function (): void {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'name' => $schema->string()->required(),
        'address' => $schema->object([
            'street' => $schema->string()->required(),
            'city' => $schema->string()->required(),
        ])->nullable(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['address']['additionalProperties'])->toBeFalse();
});
