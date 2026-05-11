<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

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

test('audio request includes instructions when provided', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['instructions'] === 'Speak slowly';
    });
});

test('audio request omits instructions when not provided', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return ! array_key_exists('instructions', json_decode($request->body(), true));
    });
});

test('audio uses default model when none specified', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['model'] === 'google/gemini-3.1-flash-tts-preview';
    });
});

test('audio request to gemini tts model uses pcm response format and pcm mime', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['response_format'] === 'pcm';
    });

    expect($response->mimeType())->toBe('audio/pcm');
});

test('audio request to gemini tts model resolves default-female voice to Kore', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'Kore';
    });
});

test('audio request to gemini tts model resolves default-male voice to Puck', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openrouter');

    Http::assertSent(function (Request $request) {
        return json_decode($request->body(), true)['voice'] === 'Puck';
    });
});

test('audio response is base64-encoded with audio/mpeg mime type', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    expect($response->audio)->toBe(base64_encode('fake-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('openai/gpt-4o-mini-tts-2025-12-15');
});

test('audio request sends bearer token', function () {
    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('audio request sends openrouter attribution headers when configured', function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
        'http_referer' => 'https://example.test',
        'x_title' => 'Example App',
    ]]);

    Http::fake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('HTTP-Referer', 'https://example.test')
            && $request->hasHeader('X-OpenRouter-Title', 'Example App');
    });
});

test('audio rate limit response throws rate limited exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Rate limit exceeded',
                'code' => 429,
            ],
        ], 429),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Service overloaded',
                'code' => 503,
            ],
        ], 503),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(ProviderOverloadedException::class);

test('audio http error response throws request exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Bad request',
                'code' => 400,
            ],
        ], 400),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(RequestException::class);
