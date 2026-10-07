<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config(['ai.providers.eleven' => [
        ...config('ai.providers.eleven'),
        'key' => 'test-key',
    ]]);
});

test('audio request includes model_id, text, and resolves default-female voice', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model_id'] === 'eleven_multilingual_v2'
            && $body['text'] === 'Hello world'
            && $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX';
    });
});

test('audio request resolves default-male voice alias', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/onwK4e9ZLuTAKqWW03F9');
});

test('audio request passes custom voice id through unchanged', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/my-custom-voice-id');
});

test('audio request sends xi-api-key header', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(fn (Request $request) => $request->hasHeader('xi-api-key', 'test-key'));
});

test('audio response is base64-encoded with audio/mpeg mime type', function (): void {
    Http::fake(['*' => Http::response('raw-audio-bytes')]);

    $response = Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    expect($response->audio)->toBe(base64_encode('raw-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('eleven')
        ->and($response->meta->model)->toBe('eleven_multilingual_v2');
});

test('audio uses default model when none specified', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model_id'] === 'eleven_multilingual_v2');
});

test('audio sends query string provider options as query parameters instead of in the body', function (bool $enableLogging, string $expected): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')
        ->withProviderOptions([
            'output_format' => 'wav_44100',
            'enable_logging' => $enableLogging,
            'optimize_streaming_latency' => 0,
        ])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(function (Request $request) use ($expected): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX?')
            && $query === ['output_format' => 'wav_44100', 'enable_logging' => $expected, 'optimize_streaming_latency' => '0']
            && ! Arr::hasAny(json_decode($request->body(), true), ['output_format', 'enable_logging', 'optimize_streaming_latency']);
    });
})->with([
    [false, 'false'],
    [true, 'true'],
]);

test('audio keeps body provider options in the request body', function (): void {
    Http::fake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')
        ->withProviderOptions([
            'output_format' => 'mp3_44100_192',
            'seed' => 42,
            'voice_settings' => ['stability' => 0.5],
            'model_id' => 'hijacked',
        ])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['seed'] === 42
            && $body['voice_settings'] === ['stability' => 0.5]
            && $body['model_id'] === 'eleven_multilingual_v2'
            && $body['text'] === 'Hello';
    });
});

test('audio response mime type follows the returned content type', function (): void {
    Http::fake(['*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/wav'])]);

    $response = Audio::of('Hello')
        ->withProviderOptions(['output_format' => 'wav_44100'])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    expect($response->mimeType())->toBe('audio/wav');
});

test('audio throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(RequestException::class);

test('audio rate limit response throws rate limited exception', function (): void {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'service unavailable'], 503)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(ProviderOverloadedException::class);

function fakeElevenAudioResponse()
{
    return Http::response('fake-audio-bytes');
}
