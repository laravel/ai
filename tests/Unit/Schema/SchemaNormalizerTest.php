<?php

use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Schema\SchemaNormalizer;

function illuminateSupportsAnyOf(): bool
{
    return class_exists('Illuminate\\JsonSchema\\Types\\AnyOfType');
}

/**
 * Assert that a raw schema normalizes into something the deserializer accepts.
 */
function normalizesWithoutThrowing(array $raw): array
{
    $normalized = SchemaNormalizer::normalize($raw);

    expect(fn () => JsonSchemaFactory::fromArray($normalized))->not->toThrow(Throwable::class);

    return $normalized;
}

test('it passes a plain object schema through unchanged', function () {
    $raw = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'description' => 'A name.']],
        'required' => ['name'],
    ];

    expect(SchemaNormalizer::normalize($raw))->toBe($raw);
});

test('it collapses a nullable anyOf to a nullable single type', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'nickname' => ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']]],
        ],
    ]);

    if (illuminateSupportsAnyOf()) {
        expect($normalized['properties']['nickname'])->toMatchArray([
            'anyOf' => [
                ['type' => 'string', 'minLength' => 1],
                ['type' => 'null'],
            ],
        ]);

        return;
    }

    expect($normalized['properties']['nickname'])->toMatchArray([
        'type' => ['string', 'null'],
        'minLength' => 1,
    ]);
});

test('it collapses a non-nullable scalar oneOf to a multi-type union', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'choice' => ['oneOf' => [['type' => 'string', 'maxLength' => 5], ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['properties']['choice'])->toMatchArray(['type' => ['string', 'integer']]);
    expect($normalized['properties']['choice'])->not->toHaveKey('maxLength');
});

test('it strips type-specific keywords from a multi-type union so it deserializes', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'value' => ['type' => ['string', 'number'], 'minLength' => 3, 'minimum' => 1],
        ],
    ]);

    expect($normalized['properties']['value']['type'])->toBe(['string', 'number']);
    expect($normalized['properties']['value'])->not->toHaveKey('minLength');
    expect($normalized['properties']['value'])->not->toHaveKey('minimum');
});

test('it merges allOf branches into the node', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'merged' => ['allOf' => [['type' => 'string'], ['description' => 'Merged.', 'minLength' => 2]]],
        ],
    ]);

    expect($normalized['properties']['merged'])->toMatchArray([
        'type' => 'string',
        'description' => 'Merged.',
        'minLength' => 2,
    ]);
});

test('it drops keywords the deserializer cannot represent', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'properties' => [
            'a' => [
                'type' => 'string',
                'not' => ['const' => 'x'],
                'patternProperties' => ['^x' => ['type' => 'string']],
                'exclusiveMinimum' => 1,
                'examples' => ['foo'],
            ],
        ],
    ]);

    expect($normalized)->not->toHaveKey('$schema');
    expect($normalized['properties']['a'])->toBe(['type' => 'string']);
});

test('it drops boolean property schemas', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'anything' => true,
            'name' => ['type' => 'string'],
        ],
        'required' => ['anything', 'name'],
    ]);

    expect($normalized['properties'])->toHaveKey('name');
    expect($normalized['properties'])->not->toHaveKey('anything');
    expect($normalized['required'])->toBe(['name']);
});

test('it drops tuple and boolean array items', function () {
    $tuple = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['list' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]]],
    ]);

    expect($tuple['properties']['list'])->not->toHaveKey('items');

    $single = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'string']]],
    ]);

    expect($single['properties']['list']['items'])->toBe(['type' => 'string']);
});

test('it inlines local $ref and drops remote or unresolvable $ref', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'local' => ['$ref' => '#/$defs/Tag', 'description' => 'A tag.'],
            'remote' => ['$ref' => 'https://example.com/schema.json'],
            'missing' => ['$ref' => '#/$defs/Nope'],
        ],
        '$defs' => [
            'Tag' => ['type' => 'string', 'enum' => ['a', 'b']],
        ],
    ]);

    expect($normalized['properties']['local'])->toMatchArray([
        'type' => 'string',
        'enum' => ['a', 'b'],
        'description' => 'A tag.',
    ]);
    expect($normalized['properties']['remote'])->toBe(['type' => 'string']);
    expect($normalized['properties']['missing'])->toBe(['type' => 'string']);
    expect($normalized)->not->toHaveKey('$defs');
});

test('it breaks circular $ref without infinite recursion', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['node' => ['$ref' => '#/$defs/Node']],
        '$defs' => [
            'Node' => [
                'type' => 'object',
                'properties' => ['child' => ['$ref' => '#/$defs/Node']],
            ],
        ],
    ]);

    expect($normalized['properties']['node']['type'])->toBe('object');
    expect($normalized['properties']['node']['properties']['child'])->toBe(['type' => 'string']);
});

test('it drops a null default', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['flag' => ['type' => 'boolean', 'default' => null]],
    ]);

    expect($normalized['properties']['flag'])->not->toHaveKey('default');
});

test('it keeps additionalProperties false but drops permissive forms', function () {
    $normalized = SchemaNormalizer::normalize([
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'open' => ['type' => 'object', 'additionalProperties' => true, 'properties' => ['x' => ['type' => 'string']]],
        ],
    ]);

    expect($normalized['additionalProperties'])->toBeFalse();
    expect($normalized['properties']['open'])->not->toHaveKey('additionalProperties');
});

test('it assigns a type to an otherwise-typeless allOf root so it does not throw', function () {
    $normalized = normalizesWithoutThrowing([
        'allOf' => [['description' => 'x'], ['title' => 'y']],
    ]);

    expect($normalized['type'])->toBe('string');
    expect($normalized)->not->toHaveKey('allOf');
});

test('it unions required across allOf branches and the outer node', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'required' => ['c'],
        'properties' => ['c' => ['type' => 'string']],
        'allOf' => [
            ['properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
            ['properties' => ['b' => ['type' => 'integer']], 'required' => ['b']],
        ],
    ]);

    expect($normalized['required'])->toEqualCanonicalizing(['a', 'b', 'c']);
    expect($normalized['properties'])->toHaveKeys(['a', 'b', 'c']);
});

test('it strips unsupported keywords pulled in from an allOf branch', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'n' => ['allOf' => [['type' => 'integer'], ['exclusiveMinimum' => 5, 'patternProperties' => ['^x' => []]]]],
        ],
    ]);

    expect($normalized['properties']['n'])->toBe(['type' => 'integer']);
});

test('it preserves object shape for a nullable object branch', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'addr' => ['anyOf' => [
                ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                ['type' => 'null'],
            ]],
        ],
    ]);

    if (illuminateSupportsAnyOf()) {
        expect($normalized['properties']['addr']['anyOf'][0]['type'])->toBe('object');
        expect($normalized['properties']['addr']['anyOf'][0]['properties'])->toHaveKey('city');

        return;
    }

    expect($normalized['properties']['addr']['type'])->toBe(['object', 'null']);
    expect($normalized['properties']['addr']['properties'])->toHaveKey('city');
});

test('it preserves anyOf compositions when the framework deserializer supports them', function () {
    if (! illuminateSupportsAnyOf()) {
        $this->markTestSkipped('The installed Illuminate JSON schema package does not support anyOf.');
    }

    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'content' => ['anyOf' => [
                [
                    'type' => 'object',
                    'properties' => ['type' => ['const' => 'article'], 'title' => ['type' => 'string']],
                    'required' => ['type', 'title'],
                ],
                [
                    'type' => 'object',
                    'properties' => ['type' => ['const' => 'image'], 'url' => ['type' => 'string']],
                    'required' => ['type', 'url'],
                ],
            ]],
        ],
    ]);

    expect($normalized['properties']['content']['anyOf'])->toHaveCount(2);
    expect($normalized['properties']['content']['anyOf'][0]['properties']['type'])->toBe([
        'enum' => ['article'],
        'type' => 'string',
    ]);
});

test('it types heterogeneous, empty, and homogeneous enums without throwing', function (array $enum, $expected) {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['e' => ['enum' => $enum]],
    ]);

    expect($normalized['properties']['e']['type'])->toBe($expected);
})->with([
    'heterogeneous' => [['low', 0, true], 'string'],
    'empty' => [[], 'string'],
    'homogeneous int' => [[1, 2, 3], 'integer'],
]);

test('it replaces a null-only type with a scalar so it deserializes', function (array $raw) {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['n' => $raw],
    ]);

    expect($normalized['properties']['n']['type'])->toBe('string');
})->with([
    'scalar null' => [['type' => 'null']],
    'null-only array' => [['type' => ['null']]],
]);

test('it re-infers a type when the declared type is not a known JSON Schema type', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'a' => ['type' => 'frobnicate'],
            'b' => ['type' => 'widget', 'properties' => ['x' => ['type' => 'string']]],
        ],
    ]);

    expect($normalized['properties']['a']['type'])->toBe('string');
    expect($normalized['properties']['b']['type'])->toBe('object');
    expect($normalized['properties']['b']['properties'])->toHaveKey('x');
});

test('it drops unknown members from a multi-type union but keeps the rest', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'v' => ['type' => ['string', 'frobnicate', 'integer']],
            'n' => ['type' => ['string', 'frobnicate', 'null']],
            'only' => ['type' => ['frobnicate']],
        ],
    ]);

    expect($normalized['properties']['v']['type'])->toBe(['string', 'integer']);
    expect($normalized['properties']['n']['type'])->toBe(['string', 'null']);
    expect($normalized['properties']['only']['type'])->toBe('string');
});

test('it produces an ObjectType for a gnarly real-world schema', function () {
    $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize([
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'properties' => [
            'mode' => ['oneOf' => [['const' => 'fast'], ['const' => 'slow']]],
            'value' => ['type' => ['string', 'number', 'boolean']],
            'tags' => ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]],
            'opts' => ['allOf' => [['type' => 'object'], ['properties' => ['x' => ['type' => 'integer']]]]],
        ],
        'required' => ['value'],
        'additionalProperties' => false,
    ]));

    expect($type)->toBeInstanceOf(ObjectType::class);
});

test('it terminates on a recursive allOf instead of hanging', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['node' => ['$ref' => '#/$defs/Node']],
        '$defs' => [
            'Node' => [
                'type' => 'object',
                'allOf' => [['$ref' => '#/$defs/Node']],
                'properties' => ['v' => ['type' => 'string']],
            ],
        ],
    ]);

    expect($normalized['properties']['node']['type'])->toBe('object');
    expect($normalized['properties']['node']['properties'])->toHaveKey('v');
});

test('it terminates on a nullable recursive union instead of hanging', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['node' => ['$ref' => '#/$defs/Node']],
        '$defs' => [
            'Node' => [
                'type' => 'object',
                'properties' => [
                    'child' => ['anyOf' => [['$ref' => '#/$defs/Node'], ['type' => 'null']]],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['node']['properties'])->toHaveKey('child');
});

test('it resolves arbitrary local JSON pointers, including escaped keys', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'a' => ['$ref' => '#/$defs/a~1b'],
            'b' => ['$ref' => '#/properties/a'],
        ],
        '$defs' => [
            'a/b' => ['type' => 'integer', 'minimum' => 1],
        ],
    ]);

    expect($normalized['properties']['a'])->toMatchArray(['type' => 'integer', 'minimum' => 1]);
    expect($normalized['properties']['b'])->toMatchArray(['type' => 'integer', 'minimum' => 1]);
});

test('it converts const to a single-value enum', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['mode' => ['const' => 'fast']],
    ]);

    expect($normalized['properties']['mode'])->toBe(['enum' => ['fast'], 'type' => 'string']);
});

test('it infers object type from additionalProperties', function (array $raw) {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['opts' => $raw],
    ]);

    expect($normalized['properties']['opts']['type'])->toBe('object');
})->with([
    'schema form' => [['additionalProperties' => ['type' => 'string']]],
    'false form' => [['additionalProperties' => false]],
]);

test('it deep-merges overlapping properties across allOf branches', function () {
    $normalized = normalizesWithoutThrowing([
        'allOf' => [
            ['properties' => ['value' => ['type' => 'string', 'minLength' => 5]]],
            ['properties' => ['value' => ['type' => 'string', 'maxLength' => 10]]],
        ],
    ]);

    expect($normalized['properties']['value'])->toMatchArray([
        'type' => 'string',
        'minLength' => 5,
        'maxLength' => 10,
    ]);
});
