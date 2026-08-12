<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => [
            'text' => ['default' => 'local-model'],
            'transcription' => ['default' => 'local-whisper'],
        ],
    ]]);
});

test('transcription posts audio to the transcriptions endpoint as multipart', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:1234/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription uses the configured default model', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'local-whisper'));
});

test('transcription throws when no default model is configured and none is passed', function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);

    Http::fake();

    expect(fn (): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'requires a default transcription model');

    Http::assertNothingSent();
});

test('transcription sends language when provided', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Bonjour'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="language"'));
});

test('transcription omits language when not provided', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
});

test('transcription requests the diarized response format when diarizing', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hi there',
        'segments' => [['text' => 'Hi there', 'speaker' => 'speaker_1', 'start' => 0.5, 'end' => 1.5]],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'diarized_json'));

    expect($response->segments)->toHaveCount(1)
        ->and($response->segments->first()->speaker)->toBe('speaker_1')
        ->and($response->segments->first()->startSeconds)->toBe(0.5);
});

test('transcription provider options override the default response format', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['response_format' => 'verbose_json', 'timestamp_granularities' => ['segment']])
        ->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'verbose_json')
        && ! str_contains($request->body(), "\r\n\r\njson\r\n"));
});

test('transcription reads chat completion style usage keys', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hi',
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    expect($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(3);
});

test('transcription reads openai style usage keys', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hi',
        'usage' => ['input_tokens' => 7, 'output_tokens' => 2],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    expect($response->usage->promptTokens)->toBe(7)
        ->and($response->usage->completionTokens)->toBe(2);
});

test('transcription uses the audio name for the upload filename', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::of((new Base64Audio(base64_encode('fake-audio'), 'audio/wav'))->as('meeting.wav'))
        ->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'filename="meeting.wav"'));
});

test('transcription derives the upload filename from the mime type', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/flac')->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'filename="audio.flac"'));
});

test('transcription response text is correctly parsed', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('openai-compatible')
        ->and($response->meta->model)->toBe('local-whisper');
});

test('transcription sends the bearer token', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Service overloaded']], 503)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid audio']], 400)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
})->throws(RequestException::class);

test('transcription can be faked for the openai-compatible provider', function (): void {
    Transcription::fake(['Faked transcript']);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

    expect($response->text)->toBe('Faked transcript');
});
