<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\InfomaniakProvider;

it('Infomaniak provider implements all required interfaces', function () {
    $provider = new InfomaniakProvider(
        ['driver' => 'infomaniak', 'key' => 'test-token'],
        app('events')
    );

    expect($provider)->toBeInstanceOf(\Laravel\Ai\Contracts\Providers\TextProvider::class)
        ->and($provider)->toBeInstanceOf(\Laravel\Ai\Contracts\Providers\EmbeddingProvider::class)
        ->and($provider)->toBeInstanceOf(\Laravel\Ai\Contracts\Providers\ImageProvider::class);
});

it('can generate images with Infomaniak', function () {
    config(['ai.providers.infomaniak' => [
        'driver' => 'infomaniak',
        'key' => 'test-token',
    ]]);

    Http::fake([
        '*/openai/images/generations' => Http::response([
            'data' => [
                ['url' => 'https://cdn.infomaniak.com/test-image.png'],
            ],
        ]),
    ]);

    $response = \Laravel\Ai\Image::of('A beautiful Swiss landscape')
        ->generate(provider: 'infomaniak', model: 'sd3');

    expect($response->firstImage()->image)->toBe('https://cdn.infomaniak.com/test-image.png');
});

it('can transcribe audio with Infomaniak', function () {
    config(['ai.providers.infomaniak' => [
        'driver' => 'infomaniak',
        'key' => 'test-token',
    ]]);

    Http::fake([
        '*/openai/audio/transcriptions' => Http::response([
            'text' => 'Bonjour, ceci est une transcription.',
            'language' => 'fr',
        ]),
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'audio').'.mp3';
    file_put_contents($tmpFile, 'fake audio content');

    try {
        $response = \Laravel\Ai\Transcription::of($tmpFile)
            ->generate(provider: 'infomaniak', model: 'whisper-1');

        expect($response->text)->toBe('Bonjour, ceci est une transcription.')
            ->and($response->language)->toBe('fr');
    } finally {
        @unlink($tmpFile);
    }
});
