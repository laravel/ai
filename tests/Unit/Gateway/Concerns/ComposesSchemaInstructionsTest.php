<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Gateway\Concerns\ComposesSchemaInstructions;

beforeEach(function () {
    $this->trait = new class
    {
        use ComposesSchemaInstructions {
            composeInstructions as public;
        }
    };

    $this->factory = new JsonSchemaTypeFactory;
});

test('returns instructions unchanged when schema is blank', function () {
    expect($this->trait->composeInstructions('Be helpful.', null))
        ->toBe('Be helpful.');
});

test('returns null when both instructions and schema are blank', function () {
    expect($this->trait->composeInstructions(null, null))
        ->toBeNull();
});

test('appends schema instruction when instructions are blank', function () {
    $schema = ['name' => $this->factory->string()->description('The name')];

    $result = $this->trait->composeInstructions(null, $schema);

    expect($result)->toContain('JSON object');
});

test('prepends instructions before schema instruction', function () {
    $schema = ['name' => $this->factory->string()->description('The name')];

    $result = $this->trait->composeInstructions('Be concise.', $schema);

    expect($result)->toStartWith('Be concise.');
    expect($result)->toContain('JSON object');
});

test('non-ascii characters in schema descriptions are not unicode-escaped', function () {
    $schema = ['name' => $this->factory->string()->description('名前を入力してください')];

    $result = $this->trait->composeInstructions(null, $schema);

    expect($result)->toContain('名前を入力してください');
    expect($result)->not->toContain('\u');
});
