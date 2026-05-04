<?php

use Laravel\Ai\Image;

test('images can be generated', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');

test('images can be generated with square size', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->square()
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');
