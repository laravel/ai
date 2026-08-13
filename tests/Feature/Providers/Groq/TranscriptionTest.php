<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        'driver' => 'groq',
        'key' => 'test-key',
    ]]);
});

test('transcription posts audio to the transcriptions endpoint as multipart', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription uses the default whisper model when none is configured', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'whisper-large-v3-turbo'));
});

test('transcription uses the configured default model when set', function (): void {
    config(['ai.providers.groq' => [
        'driver' => 'groq',
        'key' => 'test-key',
        'models' => ['transcription' => ['default' => 'custom-whisper-model']],
    ]]);

    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'custom-whisper-model'));
});

test('transcription sends language when provided', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Bonjour'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="language"'));
});

test('transcription omits language when not provided', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
});

test('transcription diarize throws a logic exception without sending a request', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    expect(fn (): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'groq'))
        ->toThrow(LogicException::class, 'does not support diarized transcription');

    Http::assertNothingSent();
});

test('transcription response text is correctly parsed', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('groq')
        ->and($response->meta->model)->toBe('whisper-large-v3-turbo');
});

test('transcription segments are parsed when a verbose response format is requested', function (): void {
    Http::fake(['*' => Http::response([
        'text' => 'Hello, world!',
        'segments' => [
            ['text' => 'Hello,', 'start' => 0.0, 'end' => 1.5],
            ['text' => 'world!', 'start' => 1.5, 'end' => 2.75],
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['response_format' => 'verbose_json'])
        ->generate(provider: 'groq');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'verbose_json')
        && substr_count($request->body(), 'name="response_format"') === 1);

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->first()->text)->toBe('Hello,')
        ->and($response->segments->first()->startSeconds)->toBe(0.0)
        ->and($response->segments->last()->endSeconds)->toBe(2.75);
});

test('transcription sends the bearer token', function (): void {
    Http::fake(['*' => Http::response(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Service overloaded']], 503)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid audio']], 400)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(RequestException::class);

test('transcription can be faked for the groq provider', function (): void {
    Transcription::fake(['Faked transcript']);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    expect($response->text)->toBe('Faked transcript');
});
