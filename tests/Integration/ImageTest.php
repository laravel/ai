<?php

use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Image;

test('images can be generated', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider)
        ->and($response->images)->not->toBeEmpty()
        ->and(base64_decode($response->images->first()->image, true))->not->toBeFalse();
})->with('image-providers');

test('images can be generated with square size', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->square()
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider)
        ->and($response->images)->not->toBeEmpty()
        ->and(base64_decode($response->images->first()->image, true))->not->toBeFalse();
})->with('image-providers');

test('openrouter images can be edited from a reference at a landscape size and quality', function (string $provider, string $apiKey, string $model): void {
    requiresApiKey($apiKey);

    $response = Image::of('Turn this into a watercolor painting.')
        ->attachments([new LocalImage(__DIR__.'/../Fixtures/Images/red.png')])
        ->landscape()
        ->quality('low')
        ->generate(provider: $provider, model: $model);

    expect($response->images)->not->toBeEmpty();
})->with([
    'gemini' => ['openrouter', 'OPENROUTER_API_KEY', 'google/gemini-3.1-flash-image'],
    'gpt-image' => ['openrouter', 'OPENROUTER_API_KEY', 'openai/gpt-image-1-mini'],
    'flux' => ['openrouter', 'OPENROUTER_API_KEY', 'black-forest-labs/flux-3-image'],
    'seedream' => ['openrouter', 'OPENROUTER_API_KEY', 'bytedance-seed/seedream-4.5'],
    'grok' => ['openrouter', 'OPENROUTER_API_KEY', 'x-ai/grok-imagine-image-2.0'],
]);
