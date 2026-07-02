<?php

use Illuminate\JsonSchema\JsonSchema;
use Laravel\Ai\Gateway\Anthropic\AnthropicStructuredOutputValidator;
use Laravel\Ai\ObjectSchema;

function anthropicStructuredOutputSchema(array $properties): array
{
    return (new ObjectSchema($properties))->toSchema();
}

test('passes when every value satisfies its constraints', function () {
    $schema = anthropicStructuredOutputSchema([
        'score' => JsonSchema::integer()->required()->min(1)->max(10),
        'summary' => JsonSchema::string()->required()->min(1)->max(280),
    ]);

    $violations = AnthropicStructuredOutputValidator::violations(
        ['score' => 5, 'summary' => 'A fine review.'],
        $schema,
    );

    expect($violations)->toBe([]);
});

test('flags a number below the minimum or above the maximum', function () {
    $schema = anthropicStructuredOutputSchema([
        'score' => JsonSchema::integer()->required()->min(1)->max(10),
    ]);

    $tooLow = AnthropicStructuredOutputValidator::violations(['score' => 0], $schema);
    $tooHigh = AnthropicStructuredOutputValidator::violations(['score' => 11], $schema);

    expect($tooLow)->toHaveCount(1)
        ->and($tooLow[0])->toContain('score')->toContain('at least 1')
        ->and($tooHigh)->toHaveCount(1)
        ->and($tooHigh[0])->toContain('score')->toContain('at most 10');
});

test('flags a value that is not a multiple of the required factor', function () {
    $schema = anthropicStructuredOutputSchema([
        'quantity' => JsonSchema::integer()->required()->multipleOf(5),
    ]);

    expect(AnthropicStructuredOutputValidator::violations(['quantity' => 7], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['quantity' => 10], $schema))
        ->toBe([]);
});

test('flags a string shorter or longer than its length bounds', function () {
    $schema = anthropicStructuredOutputSchema([
        'summary' => JsonSchema::string()->required()->min(5)->max(10),
    ]);

    expect(AnthropicStructuredOutputValidator::violations(['summary' => 'hi'], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['summary' => 'way too long a summary'], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['summary' => 'just ok'], $schema))
        ->toBe([]);
});

test('flags a string that does not match the required pattern', function () {
    $schema = anthropicStructuredOutputSchema([
        'slug' => JsonSchema::string()->required()->pattern('^[a-z]+$'),
    ]);

    expect(AnthropicStructuredOutputValidator::violations(['slug' => 'Not-A-Slug'], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['slug' => 'validslug'], $schema))
        ->toBe([]);
});

test('flags an array with too few or too many items', function () {
    $schema = anthropicStructuredOutputSchema([
        'tags' => JsonSchema::array()->required()->items(JsonSchema::string())->min(1)->max(3),
    ]);

    expect(AnthropicStructuredOutputValidator::violations(['tags' => []], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['tags' => ['a', 'b', 'c', 'd']], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['tags' => ['a', 'b']], $schema))
        ->toBe([]);
});

test('flags duplicate items when uniqueItems is required', function () {
    $schema = anthropicStructuredOutputSchema([
        'tags' => JsonSchema::array()->required()->items(JsonSchema::string())->unique(),
    ]);

    expect(AnthropicStructuredOutputValidator::violations(['tags' => ['a', 'a']], $schema))
        ->toHaveCount(1)
        ->and(AnthropicStructuredOutputValidator::violations(['tags' => ['a', 'b']], $schema))
        ->toBe([]);
});

test('recurses into array item constraints', function () {
    $schema = anthropicStructuredOutputSchema([
        'tags' => JsonSchema::array()->required()->items(JsonSchema::string()->max(3)),
    ]);

    $violations = AnthropicStructuredOutputValidator::violations(
        ['tags' => ['ok', 'way too long']],
        $schema,
    );

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('tags[1]');
});

test('recurses into nested object properties', function () {
    $schema = anthropicStructuredOutputSchema([
        'author' => JsonSchema::object([
            'age' => JsonSchema::integer()->required()->min(0)->max(120),
        ])->required(),
    ]);

    $violations = AnthropicStructuredOutputValidator::violations(
        ['author' => ['age' => 200]],
        $schema,
    );

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('author.age');
});

test('does not flag a missing required property or a mismatched type', function () {
    $schema = anthropicStructuredOutputSchema([
        'score' => JsonSchema::integer()->required()->min(1)->max(10),
    ]);

    expect(AnthropicStructuredOutputValidator::violations([], $schema))->toBe([]);
});
