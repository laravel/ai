<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Schema\SchemaRules;

function passesSchema(Closure $properties, array $data): bool
{
    $schema = (new ObjectSchema($properties(new JsonSchemaTypeFactory)))->toSchema();

    return Validator::make($data, SchemaRules::for($schema))->passes();
}

test('a value passes or fails the schema it was declared with', function (Closure $properties, array $data, bool $passes) {
    expect(passesSchema($properties, $data))->toBe($passes);
})->with([
    'required present' => [fn (JsonSchema $s) => ['name' => $s->string()->required()], ['name' => 'Taylor'], true],
    'required missing' => [fn (JsonSchema $s) => ['name' => $s->string()->required()], [], false],
    'required null' => [fn (JsonSchema $s) => ['name' => $s->string()->required()], ['name' => null], false],
    'optional missing' => [fn (JsonSchema $s) => ['name' => $s->string()], [], true],
    'nullable null' => [fn (JsonSchema $s) => ['name' => $s->string()->nullable()->required()], ['name' => null], true],
    'string given integer' => [fn (JsonSchema $s) => ['name' => $s->string()->required()], ['name' => 5], false],
    'string too long' => [fn (JsonSchema $s) => ['name' => $s->string()->max(3)->required()], ['name' => 'Taylor'], false],
    'string off pattern' => [fn (JsonSchema $s) => ['code' => $s->string()->pattern('^[A-Z]{3}$')->required()], ['code' => 'ab/'], false],
    'string on pattern' => [fn (JsonSchema $s) => ['code' => $s->string()->pattern('^[A-Z]{3}$')->required()], ['code' => 'LIS'], true],
    'string on an escaped slash pattern' => [fn (JsonSchema $s) => ['path' => $s->string()->pattern('^a\\/b$')->required()], ['path' => 'a/b'], true],
    'email format' => [fn (JsonSchema $s) => ['email' => $s->string()->format('email')->required()], ['email' => 'not-an-email'], false],
    'enum outside' => [fn (JsonSchema $s) => ['plan' => $s->string()->enum(['Basic', 'Pro'])->required()], ['plan' => 'Gold'], false],
    'enum inside' => [fn (JsonSchema $s) => ['plan' => $s->string()->enum(['Basic', 'Pro'])->required()], ['plan' => 'Pro'], true],
    'integer below minimum' => [fn (JsonSchema $s) => ['seats' => $s->integer()->min(1)->required()], ['seats' => 0], false],
    'integer given float' => [fn (JsonSchema $s) => ['seats' => $s->integer()->required()], ['seats' => 1.5], false],
    'integer given numeric string' => [fn (JsonSchema $s) => ['seats' => $s->integer()->required()], ['seats' => '5'], true],
    'number given numeric string' => [fn (JsonSchema $s) => ['ratio' => $s->number()->required()], ['ratio' => '0.5'], true],
    'boolean given string' => [fn (JsonSchema $s) => ['agree' => $s->boolean()->required()], ['agree' => '1'], true],
    'number above maximum' => [fn (JsonSchema $s) => ['ratio' => $s->number()->max(1)->required()], ['ratio' => 1.5], false],
    'boolean false' => [fn (JsonSchema $s) => ['agree' => $s->boolean()->required()], ['agree' => false], true],
    'array too many items' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string())->max(1)->required()], ['tags' => ['a', 'b']], false],
    'array with a bad item' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string()->enum(['a', 'b']))->required()], ['tags' => ['a', 'c']], false],
    'array given an object' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string())->required()], ['tags' => ['x' => 'a']], false],
    'array with duplicates' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string())->unique()->required()], ['tags' => ['a', 'a']], false],
    'object missing a nested key' => [fn (JsonSchema $s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => []], false],
    'object with an unknown key' => [fn (JsonSchema $s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => 'Lisbon', 'admin' => true]], false],
    'optional empty string' => [fn (JsonSchema $s) => ['note' => $s->string()->enum(['a', 'b'])], ['note' => ''], false],
    'empty string item' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string())->required()], ['tags' => ['']], false],
    'optional nullable null' => [fn (JsonSchema $s) => ['note' => $s->string()->nullable()], ['note' => null], true],
    'optional empty array' => [fn (JsonSchema $s) => ['tags' => $s->array()->items($s->string())], ['tags' => []], true],
    'object valid' => [fn (JsonSchema $s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => 'Lisbon']], true],
]);

test('an anyOf value passes when any branch matches', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['plan' => ['anyOf' => [['type' => 'string', 'enum' => ['Basic', 'Pro']], ['type' => 'integer']]]],
        'required' => ['plan'],
    ];

    $passes = fn (mixed $value) => Validator::make(['plan' => $value], SchemaRules::for($schema))->passes();

    expect($passes('Pro'))->toBeTrue()
        ->and($passes(3))->toBeTrue()
        ->and($passes('Gold'))->toBeFalse()
        ->and($passes(null))->toBeFalse();
});

test('a multi-type union passes any of its types', function () {
    $schema = ['type' => 'object', 'properties' => ['id' => ['type' => ['string', 'integer']]], 'required' => ['id']];

    $passes = fn (mixed $value) => Validator::make(['id' => $value], SchemaRules::for($schema))->passes();

    expect($passes('abc'))->toBeTrue()
        ->and($passes(7))->toBeTrue()
        ->and($passes(['a']))->toBeFalse();
});

test('numeric property names keep their own rules', function () {
    $schema = ['type' => 'object', 'properties' => ['1' => ['type' => 'string'], '2' => ['type' => 'integer']], 'required' => ['2']];

    expect(Validator::make([2 => 'x'], SchemaRules::for($schema))->passes())->toBeFalse()
        ->and(Validator::make([2 => 7], SchemaRules::for($schema))->passes())->toBeTrue();
});
