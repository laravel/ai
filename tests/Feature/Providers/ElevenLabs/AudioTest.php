<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;

beforeEach(function () {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
    ]]);
});

test('audio request includes model_id, text, and resolves default-female voice', function () {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model_id'] === 'eleven_multilingual_v2'
            && $body['text'] === 'Hello world'
            && $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX';
    });
});

test('audio request resolves default-male voice alias', function () {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/onwK4e9ZLuTAKqWW03F9');
});

test('audio request passes custom voice id through unchanged', function () {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/my-custom-voice-id');
});

test('audio request sends xi-api-key header', function () {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request) => $request->hasHeader('xi-api-key', 'test-key'));
});

test('audio response is base64-encoded with audio/mpeg mime type', function () {
    Http::fake(['*' => Http::response('raw-audio-bytes')]);

    $response = Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    expect($response->audio)->toBe(base64_encode('raw-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('eleven')
        ->and($response->meta->model)->toBe('eleven_multilingual_v2');
});

test('audio uses default model when none specified', function () {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model_id'] === 'eleven_multilingual_v2');
});

test('audio throws when the API returns an error', function () {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(RequestException::class);

function fakeElevenAudioResponse()
{
    return Http::response('fake-audio-bytes');
}
