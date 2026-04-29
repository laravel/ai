<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\InfomaniakProvider;

it('can be instantiated directly', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test-token'],
        app('events')
    );

    expect($provider)->toBeInstanceOf(InfomaniakProvider::class);
})->skip('Provider needs full Laravel setup');

it('has correct default text model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->defaultTextModel())->toBe('mixtral');
});

it('has correct cheapest text model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->cheapestTextModel())->toBe('mistral-7b');
});

it('has correct smartest text model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->smartestTextModel())->toBe('mixtral');
});

it('has correct default image model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->defaultImageModel())->toBe('sd3');
});

it('has correct default transcription model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->defaultTranscriptionModel())->toBe('whisper-1');
});

it('has correct default embeddings model', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test'],
        app('events')
    );

    expect($provider->defaultEmbeddingsModel())->toBe('text-embedding-3-small');
});

it('Lab enum has Infomaniak case', function () {
    expect(Lab::Infomaniak->value)->toBe('infomaniak');
    expect(Lab::from('infomaniak'))->toBe(Lab::Infomaniak);
});
