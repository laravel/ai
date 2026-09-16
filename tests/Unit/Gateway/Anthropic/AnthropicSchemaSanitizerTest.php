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
            'Must be greater than 0. Must be less than 100. Must be a multiple of 5.'
        );
});

test('drops non numeric exclusive bounds without describing a wrong bound', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'number',
        'minimum' => 1,
        'exclusiveMinimum' => true,
    ]);

    expect($result)->not->toHaveKeys(['minimum', 'exclusiveMinimum'])
        ->and($result['description'])->toBe('Must be at least 1.');
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

test('strips pattern and folds it into the description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'string',
        'pattern' => '^[a-z]+$',
    ]);

    expect($result)->not->toHaveKey('pattern')
        ->and($result['description'])->toBe('Must match the pattern ^[a-z]+$.');
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
        'maxItems' => 7,
        'items' => ['type' => 'string'],
    ]);

    expect($result['minItems'])->toBe(1)
        ->and($result['description'])->toBe('Must contain at least 3 items. Must contain at most 7 items.');
});

test('leaves supported minItems values untouched', function (int $value) {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'minItems' => $value,
        'items' => ['type' => 'string'],
    ]);

    expect($result['minItems'])->toBe($value)
        ->and($result)->not->toHaveKey('description');
})->with([0, 1]);

test('strips uniqueItems and notes it when true', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'uniqueItems' => true,
        'items' => ['type' => 'string'],
    ]);

    expect($result)->not->toHaveKey('uniqueItems')
        ->and($result['description'])->toBe('All items must be unique.');
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

test('strips object constraints and folds them into the description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'minProperties' => 2,
        'maxProperties' => 4,
        'patternProperties' => ['^x' => ['type' => 'string']],
    ]);

    expect($result)->not->toHaveKeys(['minProperties', 'maxProperties', 'patternProperties'])
        ->and($result['description'])->toBe('Must have at least 2 properties. Must have at most 4 properties.');
});

test('strips array constraints beyond a supported minItems', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'items' => ['type' => 'string'],
        'contains' => ['type' => 'string'],
        'minContains' => 2,
        'maxContains' => 4,
        'prefixItems' => [['type' => 'string']],
        'unevaluatedItems' => false,
    ]);

    expect($result)->not->toHaveKeys(['contains', 'minContains', 'maxContains', 'prefixItems', 'unevaluatedItems'])
        ->and($result['description'])->toBe('Must contain at least 2 matching items. Must contain at most 4 matching items.');
});

test('strips subschema and annotation keywords without describing them', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'string',
        'not' => ['const' => 'x'],
        'if' => ['const' => 'y'],
        'then' => ['const' => 'z'],
        'else' => ['const' => 'w'],
        'examples' => ['a'],
        'deprecated' => true,
        'readOnly' => true,
        '$comment' => 'internal',
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
    ]);

    expect($result)->toBe(['type' => 'string']);
});

test('forces additionalProperties to false', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'additionalProperties' => true,
    ]);

    expect($result['additionalProperties'])->toBeFalse();
});

test('leaves keywords outside the rejected set untouched', function () {
    $schema = [
        'type' => 'string',
        'x-internal' => 'kept',
        'unknownKeyword' => ['nested' => true],
    ];

    expect(AnthropicSchemaSanitizer::sanitize($schema))->toBe($schema);
});

test('keeps an enum of scalars but folds a complex enum into the description', function () {
    $scalars = ['type' => 'string', 'enum' => ['active', 'inactive']];

    expect(AnthropicSchemaSanitizer::sanitize($scalars))->toBe($scalars);

    $complex = AnthropicSchemaSanitizer::sanitize([
        'type' => 'object',
        'enum' => [['a' => 'x']],
    ]);

    expect($complex)->not->toHaveKey('enum')
        ->and($complex['description'])->toBe('Must be one of: [{"a":"x"}].');
});

test('rewrites a union type into anyOf branches', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => ['string', 'null'],
        'description' => 'The nickname.',
        'maxLength' => 20,
    ]);

    expect($result)->not->toHaveKeys(['type', 'maxLength'])
        ->and($result['anyOf'])->toBe([
            ['type' => 'string', 'description' => 'Must be at most 20 characters.'],
            ['type' => 'null'],
        ])
        ->and($result['description'])->toBe('The nickname.');
});

test('collapses a single member union type back to a scalar type', function () {
    expect(AnthropicSchemaSanitizer::sanitize(['type' => ['string']]))
        ->toBe(['type' => 'string']);
});

test('rewrites oneOf into the supported anyOf form', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'oneOf' => [
            ['type' => 'integer', 'minimum' => 1],
            ['type' => 'string', 'maxLength' => 5],
        ],
    ]);

    expect($result)->not->toHaveKey('oneOf')
        ->and($result['anyOf'][0])->toBe(['type' => 'integer', 'description' => 'Must be at least 1.'])
        ->and($result['anyOf'][1])->toBe(['type' => 'string', 'description' => 'Must be at most 5 characters.']);
});

test('appends constraint notes to an existing description', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'integer',
        'description' => 'The score.',
        'minimum' => 1,
    ]);

    expect($result['description'])->toBe('The score. Must be at least 1.');
});

test('terminates an existing description before appending notes', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'integer',
        'description' => 'The score',
        'minimum' => 1,
    ]);

    expect($result['description'])->toBe('The score. Must be at least 1.');
});

test('leaves supported keywords untouched', function () {
    $schema = [
        'type' => 'object',
        'title' => 'Status',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            'code' => ['type' => 'string', 'const' => 'X'],
            'role' => ['type' => 'string', 'default' => 'member'],
            'when' => ['type' => 'string', 'format' => 'date-time'],
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

test('recurses into every combinator branch', function (string $combinator) {
    $result = AnthropicSchemaSanitizer::sanitize([
        $combinator => [
            ['type' => 'integer', 'minimum' => 1],
            ['type' => 'string', 'maxLength' => 5],
        ],
    ]);

    expect($result[$combinator][0])->not->toHaveKey('minimum')
        ->and($result[$combinator][0]['description'])->toBe('Must be at least 1.')
        ->and($result[$combinator][1])->not->toHaveKey('maxLength')
        ->and($result[$combinator][1]['description'])->toBe('Must be at most 5 characters.');
})->with(['anyOf', 'allOf']);

test('recurses into reusable definitions', function (string $keyword) {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'object',
        $keyword => [
            'Score' => ['type' => 'integer', 'minimum' => 1],
        ],
        'properties' => [
            'score' => ['$ref' => '#/'.$keyword.'/Score'],
        ],
    ]);

    expect($result[$keyword]['Score'])->not->toHaveKey('minimum')
        ->and($result[$keyword]['Score']['description'])->toBe('Must be at least 1.')
        ->and($result['properties']['score'])->toBe(['$ref' => '#/'.$keyword.'/Score']);
})->with(['$defs', 'definitions']);

test('recurses into tuple form items', function () {
    $result = AnthropicSchemaSanitizer::sanitize([
        'type' => 'array',
        'items' => [
            ['type' => 'integer', 'minimum' => 1],
            ['type' => 'string', 'maxLength' => 5],
        ],
    ]);

    expect($result['items'][0]['description'])->toBe('Must be at least 1.')
        ->and($result['items'][1]['description'])->toBe('Must be at most 5 characters.');
});

test('sanitizes the schema from the issue reproduction', function () {
    $sanitized = AnthropicSchemaSanitizer::sanitize(
        (new ObjectSchema([
            'score' => JsonSchema::integer()->required()->min(1)->max(10),
            'summary' => JsonSchema::string()->required()->min(1)->max(280),
            'tags' => JsonSchema::array()->required()->items(JsonSchema::string())->min(1)->max(5),
        ]))->toSchema()
    );

    expect($sanitized['properties']['score'])->toBe([
        'type' => 'integer',
        'description' => 'Must be at least 1. Must be at most 10.',
    ])->and($sanitized['properties']['summary'])->toBe([
        'type' => 'string',
        'description' => 'Must be at least 1 character. Must be at most 280 characters.',
    ])->and($sanitized['properties']['tags'])->toBe([
        'minItems' => 1,
        'items' => ['type' => 'string'],
        'type' => 'array',
        'description' => 'Must contain at most 5 items.',
    ])->and($sanitized['additionalProperties'])->toBeFalse()
        ->and($sanitized['required'])->toBe(['score', 'summary', 'tags']);
});

test('sanitizes a nullable property built by the Illuminate JsonSchema builder', function () {
    $sanitized = AnthropicSchemaSanitizer::sanitize(
        (new ObjectSchema([
            'nickname' => JsonSchema::string()->max(20)->nullable(),
        ]))->toSchema()
    );

    expect($sanitized['properties']['nickname'])->not->toHaveKey('type')
        ->and($sanitized['properties']['nickname']['anyOf'])->toBe([
            ['type' => 'string', 'description' => 'Must be at most 20 characters.'],
            ['type' => 'null'],
        ]);
});
