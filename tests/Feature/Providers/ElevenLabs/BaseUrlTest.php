<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Transcription;

function fakeElevenBaseUrlAudioResponse(): PromiseInterface
{
    return Http::response('fake-audio-bytes');
}

function fakeElevenBaseUrlTranscriptionResponse(): PromiseInterface
{
    return Http::response(['text' => 'Hello world']);
}

test('elevenlabs audio requests use the configured base url', function () {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeElevenBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://localhost:8080/v1/text-to-speech/'));
});

test('elevenlabs transcription requests use the configured base url', function () {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeElevenBaseUrlTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'eleven', model: 'scribe_v2');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8080/v1/speech-to-text');
});

test('elevenlabs requests fall back to the default base url', function () {
    config(['ai.providers.eleven' => array_diff_key(
        [...config('ai.providers.eleven'), 'key' => 'test-key'],
        ['url' => null],
    )]);

    Http::fake(['*' => fakeElevenBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.elevenlabs.io/v1/text-to-speech/'));
});
