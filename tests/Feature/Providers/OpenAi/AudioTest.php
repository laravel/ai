<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiAudioResponse(): PromiseInterface
{
    return Http::response('fake-audio-bytes');
}

test('audio request includes model, input, voice, response format, and speed', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts'
            && $body['input'] === 'Hello world'
            && $body['voice'] === 'alloy'
            && $body['response_format'] === 'mp3'
            && $body['speed'] == 1.0
            && $request->url() === 'https://api.openai.com/v1/audio/speech';
    });
});

test('audio request resolves default-female voice to alloy', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'alloy');
});

test('audio request resolves default-male voice to ash', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'ash');
});

test('audio request passes custom voice id through unchanged', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->voice('nova')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'nova');
});

test('audio request includes instructions when provided', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['instructions'] === 'Speak slowly');
});

test('audio request includes speed when provided', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->speed(1.5)->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['speed'] == 1.5);
});

test('audio request includes format when provided', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->format('aac')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['response_format'] === 'aac');
});

test('audio response resolves correct mime type for custom format', function (string $format, string $expectedMimeType): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    $response = Audio::of('Hello')->format($format)->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($response->mimeType())->toBe($expectedMimeType);
})->with([
    ['mp3', 'audio/mpeg'],
    ['wav', 'audio/wav'],
    ['aac', 'audio/aac'],
    ['flac', 'audio/flac'],
    ['opus', 'audio/opus'],
    ['pcm', 'audio/pcm'],
]);

test('audio response is base64-encoded with audio/mpeg mime type', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($response->audio)->toBe(base64_encode('fake-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-4o-mini-tts');
});

test('audio uses default model when none specified', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gpt-4o-mini-tts');
});

test('audio rate limit response throws rate limited exception', function (): void {
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

test('audio http error response throws request exception', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Bad request',
            ],
        ], 400),
    ]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
})->throws(RequestException::class);

test('audio request sends bearer token', function (): void {
    Http::fake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});
