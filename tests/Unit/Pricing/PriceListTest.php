<?php

use Laravel\Ai\Pricing\ModelPricing;
use Laravel\Ai\Pricing\PriceList;

test('resolves a built-in default model', function () {
    $pricing = (new PriceList)->for('openai', 'gpt-4o');

    expect($pricing)->toBeInstanceOf(ModelPricing::class)
        ->and($pricing->input)->toBe(2.50)
        ->and($pricing->output)->toBe(10.00);
});

test('resolves a versioned model id by longest contained key', function () {
    $pricing = (new PriceList)->for('anthropic', 'claude-sonnet-4-6');

    expect($pricing->input)->toBe(3.00)
        ->and($pricing->output)->toBe(15.00);
});

test('prefers the longest matching key', function () {
    // "gpt-4o-mini" contains both "gpt-4o" and "gpt-4o-mini"; the longer wins.
    $pricing = (new PriceList)->for('openai', 'gpt-4o-mini-2026');

    expect($pricing->input)->toBe(0.15)
        ->and($pricing->output)->toBe(0.60);
});

test('returns null for an unknown provider or model', function () {
    $prices = new PriceList;

    expect($prices->for('openai', 'nonexistent-model'))->toBeNull()
        ->and($prices->for('made-up-provider', 'whatever'))->toBeNull()
        ->and($prices->for(null, null))->toBeNull();
});

test('config overrides win over defaults', function () {
    $prices = new PriceList([
        'openai' => ['gpt-4o' => ['input' => 99.0, 'output' => 100.0]],
    ]);

    $pricing = $prices->for('openai', 'gpt-4o');

    expect($pricing->input)->toBe(99.0)
        ->and($pricing->output)->toBe(100.0);
});

test('config can add a brand new model', function () {
    $prices = new PriceList([
        'custom' => ['my-model' => ['input' => 1.0, 'output' => 2.0]],
    ]);

    expect($prices->for('custom', 'my-model')->input)->toBe(1.0);
});

test('runtime registration wins over everything', function () {
    $prices = (new PriceList([
        'openai' => ['gpt-4o' => ['input' => 99.0, 'output' => 100.0]],
    ]))->register('openai', 'gpt-4o', new ModelPricing(input: 1.0, output: 2.0));

    expect($prices->for('openai', 'gpt-4o')->input)->toBe(1.0);
});
