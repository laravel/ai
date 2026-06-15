<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiAudioResponse(): PromiseInterface
{
    return Http::response('fake-audio-bytes');
}

test('audio request includes model, input, voice, response format, and speed', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts'
            && $body['input'] === 'Hello world'
            && $body['voice'] === 'alloy'
            && $body['response_format'] === 'mp3'
            && $body['speed'] == 1.0
            && $request->url() === 'https://api.openai.com/v1/audio/speech';
    });
});

test('audio request resolves default-female voice to alloy', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'alloy';
    });
});

test('audio request resolves default-male voice to ash', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'ash';
    });
});

test('audio request passes custom voice id through unchanged', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->voice('nova')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'nova';
    });
});

test('audio request includes instructions when provided', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['instructions'] === 'Speak slowly';
    });
});

test('audio response is base64-encoded with audio/mpeg mime type', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($response->audio)->toBe(base64_encode('fake-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-4o-mini-tts');
});

test('audio uses default model when none specified', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'gpt-4o-mini-tts');
});

test('audio rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
})->throws(RateLimitedException::class);

test('audio http error response throws request exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Bad request',
            ],
        ], 400),
    ]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
})->throws(ProviderRequestException::class);

test('audio request sends bearer token', function () {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});
