<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenRouterAudioResponse(): PromiseInterface
{
    return Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']);
}

test('audio request includes model, input, voice, response format, and speed', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'openai/gpt-4o-mini-tts-2025-12-15'
            && $body['input'] === 'Hello world'
            && $body['voice'] === 'alloy'
            && $body['response_format'] === 'mp3'
            && $body['speed'] == 1.0
            && $request->url() === 'https://openrouter.ai/api/v1/audio/speech';
    });
});

test('audio request resolves default-female voice to alloy', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'alloy';
    });
});

test('audio request resolves default-male voice to ash', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'ash';
    });
});

test('audio request passes custom voice id through unchanged', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->voice('shimmer')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'shimmer';
    });
});
