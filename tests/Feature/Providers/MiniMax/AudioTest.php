<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config(['ai.providers.minimax' => [
        ...config('ai.providers.minimax'),
        'key' => 'test-key',
    ]]);
});

test('audio request maps the model, text, voice, and audio settings', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'speech-2.8-hd'
            && $body['text'] === 'Hello world'
            && $body['stream'] === false
            && $body['output_format'] === 'hex'
            && $body['voice_setting']['voice_id'] === 'English_Graceful_Lady'
            && $body['audio_setting']['format'] === 'mp3'
            && $request->url() === 'https://api.minimax.io/v1/t2a_v2';
    });
});

test('audio request resolves the default-male voice alias', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_setting']['voice_id'] === 'English_Persuasive_Man');
});

test('audio request passes a custom voice id through unchanged', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello')->voice('English_expressive_narrator')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_setting']['voice_id'] === 'English_expressive_narrator');
});

test('audio request sends a bearer authorization header', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('audio request passes each speech model through unchanged', function (string $model): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax', model: $model);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === $model);
})->with([
    'speech-2.8-hd',
    'speech-2.8-turbo',
    'speech-2.6-hd',
    'speech-2.6-turbo',
    'speech-02-hd',
    'speech-02-turbo',
    'speech-01-hd',
    'speech-01-turbo',
]);

test('audio uses the default model when none is specified', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'minimax');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'speech-2.8-hd');
});

test('hex encoded audio is decoded and re-encoded as base64', function (): void {
    Http::fake(['*' => fakeMiniMaxAudioResponse('raw-audio-bytes')]);

    $response = Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');

    expect($response->audio)->toBe(base64_encode('raw-audio-bytes'))
        ->and($response->content())->toBe('raw-audio-bytes')
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('minimax')
        ->and($response->meta->model)->toBe('speech-2.8-hd');
});

test('audio throws when the payload reports a failing status code', function (): void {
    Http::fake(['*' => Http::response([
        'base_resp' => ['status_code' => 1004, 'status_msg' => 'authentication failed'],
    ])]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(RuntimeException::class, 'MiniMax API returned an error: authentication failed');

test('audio throws when the payload contains no audio', function (): void {
    Http::fake(['*' => Http::response([
        'data' => ['status' => 2],
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
    ])]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(RuntimeException::class, 'No audio data received from MiniMax API.');

test('audio throws when the payload audio is not valid hex', function (): void {
    Http::fake(['*' => Http::response([
        'data' => ['audio' => 'not-hex', 'status' => 2],
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
    ])]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(RuntimeException::class, 'MiniMax returned invalid audio data.');

test('audio throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['base_resp' => ['status_code' => 1004]], 401)]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(RequestException::class);

test('audio rate limit response throws rate limited exception', function (): void {
    Http::fake(['api.minimax.io/*' => Http::response(['base_resp' => ['status_code' => 1002]], 429)]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['api.minimax.io/*' => Http::response(['base_resp' => ['status_code' => 1000]], 503)]);

    Audio::of('Hello')->generate(provider: 'minimax', model: 'speech-2.8-hd');
})->throws(ProviderOverloadedException::class);

function fakeMiniMaxAudioResponse(string $audio = 'fake-audio-bytes'): PromiseInterface
{
    return Http::response([
        'data' => ['audio' => bin2hex($audio), 'status' => 2],
        'extra_info' => ['audio_format' => 'mp3'],
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
    ]);
}
