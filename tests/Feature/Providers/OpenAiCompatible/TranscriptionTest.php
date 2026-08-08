<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
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

test('transcription diarize throws a logic exception without sending a request', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    expect(fn (): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openai-compatible'))
        ->toThrow(LogicException::class, 'does not support diarized transcription');

    Http::assertNothingSent();
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
