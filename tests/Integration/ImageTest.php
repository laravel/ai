<?php

use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Image;

use function Laravel\Ai\agent;

test('images can be generated', function () {
    requiresApiKey('XAI_API_KEY');

    $response = Image::of('Donut sitting on a kitchen counter.')->generate(provider: ['xai']);

    expect($response->meta->provider)->toEqual('xai');
});

test('local png attachments work without an explicit mime type', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent('Answer briefly.')->prompt(
        'What color is the background of this image? Answer with just one word.',
        attachments: [new LocalImage(__DIR__.'/../Fixtures/Images/red.png')],
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toBeString()->not->toBeEmpty()
        ->and(strtolower($response->text))->toContain('red');
})->with('agent-image-providers');

test('local jpeg attachments detect the correct mime type instead of falling back to png', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent('Answer briefly.')->prompt(
        'What color is the background of this image? Answer with just one word.',
        attachments: [new LocalImage(__DIR__.'/../Fixtures/Images/blue.jpg')],
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toBeString()->not->toBeEmpty()
        ->and(strtolower($response->text))->toContain('blue');
})->with('agent-image-providers');
