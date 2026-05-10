<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Files;
use Laravel\Ai\Transcription;

test('audio can be generated', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    Event::fake();

    $response = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    expect($response->meta->provider)->toEqual($provider)
        ->and($response->audio)->not->toBeEmpty()
        ->and($response->mimeType())->not->toBeNull();

    Event::assertDispatched(GeneratingAudio::class, fn (GeneratingAudio $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(AudioGenerated::class, fn (AudioGenerated $event) => $event->prompt->timeout === 30);
})->with('tts-providers');

test('transcription can be generated from local path', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $transcription = Files\Audio::fromPath(__DIR__.'/../Fixtures/audio.mp3')
        ->transcription()
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue();
})->with('transcription-providers');

test('audio can be transcribed after generation', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue();
})->with('tts-providers');

test('transcription can be diarized', function (string $provider, string $apiKey) {
    requiresApiKey($apiKey);

    $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
        ->diarize()
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue()
        ->and($transcription->segments->count())->toBeGreaterThan(0);
})->with('diarization-providers');
