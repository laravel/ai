<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Files;
use Laravel\Ai\Transcription;

uses()->group('integration');

test('audio can be generated and transcribed', function () {
    Event::fake();

    $response = Audio::of('Hello there! How are you today?')->generate();
    expect($response->meta->provider)->toEqual('openai');

    Event::assertDispatched(GeneratingAudio::class, fn (GeneratingAudio $event) => $event->prompt->timeout === 30);
    Event::assertDispatched(AudioGenerated::class, fn (AudioGenerated $event) => $event->prompt->timeout === 30);

    $transcription = Transcription::of($response->audio)->generate();
    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue()
        ->and($transcription->segments->count())->toEqual(0);

    $transcription = Transcription::of($response->audio)->diarize()->generate();
    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue()
        ->and($transcription->segments->count())->toBeGreaterThan(0);
});

test('transcription can be generated from local path', function () {
    $transcription = Files\Audio::fromPath(__DIR__.'/files/audio.mp3')
        ->transcription()
        ->generate();

    expect(str_contains(strtolower((string) $transcription), 'how are you today'))->toBeTrue();
});
