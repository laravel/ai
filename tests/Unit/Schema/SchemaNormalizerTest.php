<?php

use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Serializer;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Schema\SchemaNormalizer;

function supportsNativeAnyOf(): bool
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

test('it preserves a nullable anyOf when the framework supports it natively', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'nickname' => ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']]],
        ],
    ]);

    expect($normalized['properties']['nickname'])->toMatchArray([
        'anyOf' => [
            ['type' => 'string', 'minLength' => 1],
            ['type' => 'null'],
        ],
    ]);
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it collapses a nullable anyOf to a nullable single type when native support is unavailable', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'nickname' => ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']]],
        ],
    ]);

    expect($normalized['properties']['nickname'])->toMatchArray([
        'type' => ['string', 'null'],
        'minLength' => 1,
    ]);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback collapse.');

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

test('it preserves object shape inside nullable anyOf when the framework supports it natively', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'addr' => ['anyOf' => [
                ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                ['type' => 'null'],
            ]],
        ],
    ]);

    expect($normalized['properties']['addr']['anyOf'][0]['type'])->toBe('object');
    expect($normalized['properties']['addr']['anyOf'][0]['properties'])->toHaveKey('city');
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it preserves object shape for a nullable object branch when native support is unavailable', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'addr' => ['anyOf' => [
                ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                ['type' => 'null'],
            ]],
        ],
    ]);

    expect($normalized['properties']['addr']['type'])->toBe(['object', 'null']);
    expect($normalized['properties']['addr']['properties'])->toHaveKey('city');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback collapse.');

test('it preserves anyOf compositions when the framework deserializer supports them', function () {
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
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it folds outer object siblings into each branch so the deserializer keeps them', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string']],
        'required' => ['kind'],
        'anyOf' => [
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
        ],
    ]);

    expect($normalized)->not->toHaveKey('properties');
    expect($normalized['anyOf'])->toHaveCount(2);

    expect($normalized['anyOf'][0]['properties'])->toHaveKeys(['kind', 'name']);
    expect($normalized['anyOf'][0]['required'])->toEqualCanonicalizing(['kind', 'name']);
    expect($normalized['anyOf'][1]['properties'])->toHaveKeys(['kind', 'age']);
    expect($normalized['anyOf'][1]['required'])->toEqualCanonicalizing(['kind', 'age']);
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it keeps the shared base property after round-tripping a sibling anyOf through the deserializer', function () {
    $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string']],
        'required' => ['kind'],
        'anyOf' => [
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
        ],
    ]));

    expect(json_encode((new Serializer)->serialize($type)))->toContain('"kind"');
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it keeps the outer description on a preserved anyOf composition', function () {
    $normalized = normalizesWithoutThrowing([
        'description' => 'A union.',
        'anyOf' => [['type' => 'string'], ['type' => 'integer']],
    ]);

    expect($normalized['description'])->toBe('A union.');
    expect($normalized['anyOf'])->toHaveCount(2);
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it drops empty branches from a preserved anyOf instead of inventing a string branch', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'v' => ['anyOf' => [[], ['type' => 'string'], ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['properties']['v']['anyOf'])->toBe([
        ['type' => 'string'],
        ['type' => 'integer'],
    ]);
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it drops a cyclic anyOf branch instead of inventing a string branch', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['node' => ['$ref' => '#/$defs/Node']],
        '$defs' => [
            'Node' => ['anyOf' => [['$ref' => '#/$defs/Node'], ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['properties']['node']['anyOf'])->toBe([['type' => 'integer']]);
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

test('it drops a preserved anyOf made up entirely of null branches', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'v' => ['anyOf' => [['type' => 'null'], ['type' => 'null']]],
        ],
    ]);

    expect($normalized['properties']['v'])->not->toHaveKey('anyOf');
    expect($normalized['properties']['v']['type'])->toBe('string');
})->skip(! supportsNativeAnyOf(), 'The installed Illuminate JSON schema package does not support anyOf.');

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

test('it merges all object variants from anyOf instead of discarding all but the first', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'anyOf' => [
                        [
                            'type' => 'object',
                            'properties' => ['type' => ['type' => 'string', 'enum' => ['open']], 'text' => ['type' => 'string']],
                            'required' => ['type', 'text'],
                        ],
                        [
                            'type' => 'object',
                            'properties' => ['type' => ['type' => 'string', 'enum' => ['multiple_choice']], 'choices' => ['type' => 'array']],
                            'required' => ['type', 'choices'],
                        ],
                        [
                            'type' => 'object',
                            'properties' => ['type' => ['type' => 'string', 'enum' => ['rating']], 'scale' => ['type' => 'integer']],
                            'required' => ['type'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $item = $normalized['properties']['questions']['items'];

    expect($item['type'])->toBe('object');
    expect($item['properties'])->toHaveKeys(['type', 'text', 'choices', 'scale']);
    expect($item['required'])->toBe(['type']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it falls back to the single object branch when fewer than two object variants are present', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'value' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
                    ['type' => 'string'],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['value']['type'])->toBe('object');
    expect($normalized['properties']['value']['properties'])->toHaveKey('x');
    expect($normalized['properties']['value']['properties'])->not->toHaveKey('y');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it merges the object variants and ignores scalar branches when two or more objects are present', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'value' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
                    ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
                    ['type' => 'string'],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['value']['type'])->toBe('object');
    expect($normalized['properties']['value']['properties'])->toHaveKeys(['x', 'y']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it recursively merges branch refinements into a generic outer property', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string']],
        'anyOf' => [
            ['type' => 'object', 'properties' => ['kind' => ['const' => 'a']]],
            ['type' => 'object', 'properties' => ['kind' => ['const' => 'b']]],
        ],
    ]);

    expect($normalized['properties']['kind']['enum'])->toBe(['a', 'b']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it recursively merges duplicate nested object properties across object variants', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'item' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
                    ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['item']['properties']['payload']['properties'])->toHaveKeys(['a', 'b']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it ignores a no-opinion variant when intersecting required on a nested object property', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a']]]],
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'string']], 'required' => ['a']]]],
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'd' => ['type' => 'string']]]]],
        ],
    ]);

    expect($normalized['properties']['payload']['required'])->toBe(['a']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it keeps a nested object property open unless every variant closes it', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false]]],
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
        ],
    ]);

    expect($normalized['properties']['payload'])->not->toHaveKey('additionalProperties');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it closes a nested object property only when every variant closes it', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false]]],
            ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'additionalProperties' => false]]],
        ],
    ]);

    expect($normalized['properties']['payload']['additionalProperties'])->toBeFalse();
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it unions scalar property types when the same key differs across object variants', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['properties']['id']['type'])->toBe(['string', 'integer']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it keeps the nested object shape when one variant types a property as an object', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]]]],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ],
    ]);

    expect($normalized['properties']['id']['type'])->toBe('object');
    expect($normalized['properties']['id']['properties'])->toHaveKey('n');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it does not throw when an object variant has a malformed scalar properties value', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'item' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => 'malformed-scalar'],
                    ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['item']['properties'])->toHaveKey('b');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it preserves outer schema properties and required when merging anyOf object variants', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string']],
        'required' => ['kind'],
        'anyOf' => [
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
        ],
    ]);

    expect($normalized['properties'])->toHaveKeys(['kind', 'name', 'age']);
    expect($normalized['required'])->toContain('kind');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it treats a branch with no required key as no-opinion rather than zero-required', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'anyOf' => [
                        ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'text' => ['type' => 'string']], 'required' => ['type', 'text']],
                        ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'choices' => ['type' => 'array']], 'required' => ['type']],
                        ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'scale' => ['type' => 'integer']]],
                    ],
                ],
            ],
        ],
    ]);

    $item = $normalized['properties']['questions']['items'];

    expect($item['type'])->toBe('object');
    expect($item['properties'])->toHaveKeys(['type', 'text', 'choices', 'scale']);
    expect($item['required'])->toBe(['type']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it merges nullable object variants and marks result nullable', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'payload' => [
                'anyOf' => [
                    ['type' => ['object', 'null'], 'properties' => ['x' => ['type' => 'string']]],
                    ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['payload']['type'])->toBe(['object', 'null']);
    expect($normalized['properties']['payload']['properties'])->toHaveKeys(['x', 'y']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it does not promote required fields when only one of multiple branches declares required', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']], 'required' => ['id']],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
        ],
    ]);

    expect($normalized)->not->toHaveKey('required');
    expect($normalized['properties'])->toHaveKeys(['id', 'name', 'code']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it unions enum values for overlapping discriminator properties across anyOf variants', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['open']],    'text' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['choice']],  'choices' => ['type' => 'array']]],
            ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['rating']],  'scale' => ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['properties']['kind']['enum'])->toContain('open')
        ->toContain('choice')
        ->toContain('rating');
    expect($normalized['properties'])->toHaveKeys(['kind', 'text', 'choices', 'scale']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it does not bleed branch-specific keys like additionalProperties into the merged result', function () {
    $normalized = normalizesWithoutThrowing([
        'type' => 'object',
        'properties' => [
            'value' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'additionalProperties' => false],
                    ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
                ],
            ],
        ],
    ]);

    expect($normalized['properties']['value']['properties'])->toHaveKeys(['x', 'y']);
    expect($normalized['properties']['value'])->not->toHaveKey('additionalProperties');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it prefers the object branch over a scalar branch regardless of branch order', function () {
    $stringFirst = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'string'],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
        ],
    ]);

    $objectFirst = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ['type' => 'string'],
        ],
    ]);

    expect($stringFirst['type'])->toBe('object');
    expect($stringFirst['properties'])->toHaveKey('id');
    expect($stringFirst['required'])->toBe(['id']);
    expect($objectFirst)->toBe($stringFirst);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it skips an empty no-opinion branch when intersecting required across object variants', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['behavioral']], 'key' => ['type' => 'string'], 'value' => ['type' => 'string'], 'event_type' => ['type' => 'string']], 'required' => ['type', 'key', 'value', 'event_type']],
            ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['person']], 'key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['type', 'key', 'value']],
            ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['cohort']], 'key' => ['type' => 'string']], 'required' => ['type', 'key']],
            [],
        ],
    ]);

    expect($normalized['type'])->toBe('object');
    expect($normalized['required'])->toBe(['type', 'key']);
    expect($normalized['properties']['type']['enum'])->toBe(['behavioral', 'person', 'cohort']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it skips a leading empty branch instead of degrading the union to a string', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            [],
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']], 'required' => ['b']],
        ],
    ]);

    expect($normalized['type'])->toBe('object');
    expect($normalized['properties'])->toHaveKeys(['a', 'b']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it carries the first object branch description into the merged result', function () {
    $normalized = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'description' => 'Variant A', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
        ],
    ]);

    expect($normalized['description'])->toBe('Variant A');
    expect($normalized['properties'])->toHaveKeys(['a', 'b']);
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

test('it keeps additionalProperties false only when every merged object branch agrees', function () {
    $unanimous = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']], 'additionalProperties' => false],
        ],
    ]);

    $mixed = normalizesWithoutThrowing([
        'anyOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
        ],
    ]);

    expect($unanimous['additionalProperties'])->toBeFalse();
    expect($mixed)->not->toHaveKey('additionalProperties');
})->skip(supportsNativeAnyOf(), 'Native anyOf support preserves compositions instead of using the fallback merge.');

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
