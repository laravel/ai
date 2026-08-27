<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('audio request includes model, input, voice_id, and resolves default-female voice', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.mistral.ai/v1/audio/speech'
            && $body['model'] === 'voxtral-mini-tts-2603'
            && $body['input'] === 'Hello world'
            && $body['voice_id'] === 'gb_jane_neutral'
            && $body['response_format'] === 'mp3';
    });
});

test('audio request resolves default-male voice alias', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_id'] === 'en_paul_neutral');
});

test('audio request passes custom voice id through unchanged', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_id'] === 'my-custom-voice-id');
});

test('audio request includes format when provided', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    $response = Audio::of('Hello')->format('wav')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['response_format'] === 'wav');
    expect($response->mimeType())->toBe('audio/wav');
});

test('audio request omits instructions since the Mistral API does not accept them', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->instructions('Speak warmly')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'Speak warmly')
        && ! array_key_exists('instructions', json_decode($request->body(), true)));
});

test('audio request sends bearer token', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('audio response passes base64 audio data through with audio/mpeg mime type', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse(base64_encode('raw-audio-bytes'))]);

    $response = Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    expect($response->audio)->toBe(base64_encode('raw-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('mistral')
        ->and($response->meta->model)->toBe('voxtral-mini-tts-2603');
});

test('audio uses default model when none specified', function (): void {
    Http::fake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'mistral');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'voxtral-mini-tts-2603');
});

test('audio throws when the response contains no audio data', function (): void {
    Http::fake(['*' => Http::response(['audio_data' => null])]);

    Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
})->throws(RuntimeException::class, 'No audio data received from Mistral API.');

test('audio throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
})->throws(RequestException::class);

test('audio rate limit response throws rate limited exception', function (): void {
    Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'rate limit exceeded'], 429)]);

    Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'service unavailable'], 503)]);

    Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
})->throws(ProviderOverloadedException::class);

test('audio gateway is shared with the other Mistral gateways', function (): void {
    $provider = Ai::audioProvider('mistral');

    expect($provider->audioGateway())->toBe($provider->transcriptionGateway())
        ->and($provider->audioGateway())->toBe($provider->embeddingGateway());
});

function fakeMistralAudioResponse(?string $audioData = null)
{
    return Http::response([
        'audio_data' => $audioData ?? base64_encode('fake-audio-bytes'),
    ]);
}
