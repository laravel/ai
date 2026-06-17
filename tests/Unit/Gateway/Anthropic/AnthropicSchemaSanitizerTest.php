<?php

use Illuminate\JsonSchema\JsonSchema;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;

test('strips numeric constraints and folds them into the description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 10,
    ]);

    expect($result)->not->toHaveKeys(['minimum', 'maximum'])
        ->and($result['type'])->toBe('integer')
        ->and($result['description'])->toBe('Must be at least 1. Must be at most 10.');
});

test('strips multipleOf and exclusive bounds', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'number',
        'multipleOf' => 5,
        'exclusiveMinimum' => 0,
        'exclusiveMaximum' => 100,
    ]);

    expect($result)->not->toHaveKeys(['multipleOf', 'exclusiveMinimum', 'exclusiveMaximum'])
        ->and($result['description'])->toBe(
            'Must be a multiple of 5. Must be greater than 0. Must be less than 100.'
        );
});

test('strips string length constraints with correct pluralization', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'string',
        'minLength' => 1,
        'maxLength' => 255,
    ]);

    expect($result)->not->toHaveKeys(['minLength', 'maxLength'])
        ->and($result['description'])->toBe('Must be at least 1 character. Must be at most 255 characters.');
});

test('strips maxItems and folds it into the description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'maxItems' => 5,
        'items' => ['type' => 'string'],
    ]);

    expect($result)->not->toHaveKey('maxItems')
        ->and($result['description'])->toBe('Must contain at most 5 items.');
});

test('clamps minItems greater than one down to one', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'minItems' => 3,
        'items' => ['type' => 'string'],
    ]);

    expect($result['minItems'])->toBe(1)
        ->and($result['description'])->toBe('Must contain at least 3 items.');
});

test('leaves supported minItems values untouched', function () {
    foreach ([0, 1] as $value) {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'minItems' => $value,
            'items' => ['type' => 'string'],
        ]);

        expect($result['minItems'])->toBe($value)
            ->and($result)->not->toHaveKey('description');
    }
});

test('strips uniqueItems and notes it only when true', function () {
    $withUnique = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'uniqueItems' => true,
        'items' => ['type' => 'string'],
    ]);

    expect($withUnique)->not->toHaveKey('uniqueItems')
        ->and($withUnique['description'])->toBe('All items must be unique.');

    $withoutUnique = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'uniqueItems' => false,
        'items' => ['type' => 'string'],
    ]);

    expect($withoutUnique)->not->toHaveKey('uniqueItems')
        ->and($withoutUnique)->not->toHaveKey('description');
});

test('strips unsupported formats but keeps supported ones', function () {
    $unsupported = AnthropicSchemaSanitizer::sanitize([
        'type' => 'string',
        'format' => 'phone',
    ]);

    expect($unsupported)->not->toHaveKey('format')
        ->and($unsupported['description'])->toBe('Format: phone.');

    $supported = AnthropicSchemaSanitizer::sanitize([
        'type' => 'string',
        'format' => 'email',
    ]);

    expect($supported['format'])->toBe('email')
        ->and($supported)->not->toHaveKey('description');
});

test('appends constraint notes to an existing description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'integer',
        'description' => 'The score.',
        'minimum' => 1,
    ]);

    expect($result['description'])->toBe('The score. Must be at least 1.');
});

test('leaves supported keywords untouched', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            'code' => ['type' => 'string', 'const' => 'X'],
            'slug' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
            'role' => ['type' => 'string', 'default' => 'member'],
        ],
        'required' => ['status'],
        'additionalProperties' => false,
    ];

    expect(AnthropicSchemaSanitizer::sanitize($schema))->toBe($schema);
});

test('recurses into nested properties and array items', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'object',
        'properties' => [
            'tags' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => ['type' => 'string', 'maxLength' => 20],
            ],
            'score' => ['type' => 'integer', 'minimum' => 0],
        ],
        'additionalProperties' => false,
    ]);

    expect($result['properties']['tags'])->not->toHaveKey('maxItems')
        ->and($result['properties']['tags']['description'])->toBe('Must contain at most 3 items.')
        ->and($result['properties']['tags']['items'])->not->toHaveKey('maxLength')
        ->and($result['properties']['tags']['items']['description'])->toBe('Must be at most 20 characters.')
        ->and($result['properties']['score'])->not->toHaveKey('minimum')
        ->and($result['properties']['score']['description'])->toBe('Must be at least 0.');
});

test('recurses into combinator branches', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'anyOf' => [
            ['type' => 'integer', 'minimum' => 1],
            ['type' => 'string', 'maxLength' => 5],
        ],
    ]);

    expect($result['anyOf'][0])->not->toHaveKey('minimum')
        ->and($result['anyOf'][0]['description'])->toBe('Must be at least 1.')
        ->and($result['anyOf'][1])->not->toHaveKey('maxLength')
        ->and($result['anyOf'][1]['description'])->toBe('Must be at most 5 characters.');
});

test('sanitizes a schema produced by the Illuminate JsonSchema builder', function () {
    $sanitized = AnthropicSchemaSanitizer::sanitize(
        (new ObjectSchema([
            'score' => JsonSchema::integer()->required()->min(1)->max(10),
            'name' => JsonSchema::string()->required()->min(2)->max(50),
        ]))->toSchema()
    );

    // Constraints must not survive on the wire; additionalProperties stays false.
    expect(json_encode($sanitized))
        ->not->toContain('minimum')
        ->not->toContain('maximum')
        ->not->toContain('minLength')
        ->not->toContain('maxLength')
        ->and($sanitized['additionalProperties'])->toBeFalse()
        ->and($sanitized['properties']['score']['description'])->toContain('Must be at least 1.')
        ->and($sanitized['properties']['name']['description'])->toContain('Must be at most 50 characters.');
});
